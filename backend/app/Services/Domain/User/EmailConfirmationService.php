<?php

namespace HiEvents\Services\Domain\User;

use Carbon\Carbon;
use HiEvents\DomainObjects\AccountUserDomainObject;
use HiEvents\DomainObjects\UserDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Mail\Account\ConfirmEmailAddressEmail;
use HiEvents\Mail\Account\EmailConfirmationCodeEmail;
use HiEvents\Repository\Interfaces\AccountUserRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\UserRepositoryInterface;
use HiEvents\Services\Infrastructure\Encryption\EncryptedPayloadService;
use HiEvents\Services\Infrastructure\Encryption\Exception\DecryptionFailedException;
use HiEvents\Services\Infrastructure\Encryption\Exception\EncryptedPayloadExpiredException;
use HiEvents\Services\Infrastructure\User\EmailVerificationCodeService;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Database\DatabaseManager;
use Throwable;

class EmailConfirmationService
{
    public function __construct(
        private readonly Mailer $mailer,
        private readonly EncryptedPayloadService $encryptedPayloadService,
        private readonly UserRepositoryInterface $userRepository,
        private readonly DatabaseManager $databaseManager,
        private readonly EmailVerificationCodeService $emailVerificationCodeService,
        private readonly VerifyUserEmailService $verifyUserEmailService,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly AccountUserRepositoryInterface $accountUserRepository,
    ) {}

    /**
     * @throws DecryptionFailedException
     * @throws EncryptedPayloadExpiredException|Throwable
     */
    public function confirmEmailAddress(string $token, int $accountId): void
    {
        $this->databaseManager->transaction(function () use ($accountId, $token) {
            ['id' => $userId] = $this->encryptedPayloadService->decryptPayload($token);

            $user = $this->userRepository->findByIdAndAccountId($userId, $accountId);

            $this->verifyUserEmailService->markEmailAsVerified($user, $accountId);
        });
    }

    /**
     * Confirmation from the link in the email, without a session: the encrypted token is the proof.
     * Older links only carry the user id, so every account the user owns is verified.
     *
     * @throws DecryptionFailedException
     * @throws EncryptedPayloadExpiredException|Throwable
     */
    public function confirmEmailAddressFromLink(string $token): void
    {
        $this->databaseManager->transaction(function () use ($token) {
            $payload = $this->encryptedPayloadService->decryptPayload($token);
            $user = $this->userRepository->findById((int) $payload['id']);

            $accountIds = isset($payload['account_id'])
                ? [(int) $payload['account_id']]
                : $this->accountUserRepository
                    ->findWhere(['user_id' => $user->getId()])
                    ->map(fn (AccountUserDomainObject $accountUser) => $accountUser->getAccountId())
                    ->all();

            if ($accountIds === []) {
                throw new ResourceNotFoundException;
            }

            foreach ($accountIds as $accountId) {
                $this->verifyUserEmailService->markEmailAsVerified($user, $accountId);
            }
        });
    }

    public function sendConfirmation(UserDomainObject $user, int $accountId): void
    {
        // If there are no events, we assume the user is registering for the first time
        $events = $this->eventRepository->findWhere([
            'account_id' => $accountId,
        ]);

        if (config('app.enforce_email_confirmation_during_registration') && $events->isEmpty()) {
            $this->mailer
                ->to($user->getEmail())
                ->locale($user->getLocale())
                ->send(new EmailConfirmationCodeEmail(
                    $user,
                    $this->emailVerificationCodeService->storeAndReturnCode($user->getEmail()),
                ));

            return;
        }

        $token = $this->encryptedPayloadService->encryptPayload([
            'id' => $user->getId(),
            'account_id' => $accountId,
        ], Carbon::now()->addMonths(6));

        $this->mailer
            ->to($user->getEmail())
            ->send(new ConfirmEmailAddressEmail($user, $token));
    }
}
