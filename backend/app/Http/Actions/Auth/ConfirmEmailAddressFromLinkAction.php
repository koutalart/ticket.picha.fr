<?php

namespace HiEvents\Http\Actions\Auth;

use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Domain\User\EmailConfirmationService;
use HiEvents\Services\Infrastructure\Encryption\Exception\DecryptionFailedException;
use HiEvents\Services\Infrastructure\Encryption\Exception\EncryptedPayloadExpiredException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Throwable;

class ConfirmEmailAddressFromLinkAction extends BaseAction
{
    public function __construct(
        private readonly EmailConfirmationService $emailConfirmationService,
    ) {}

    /**
     * @throws Throwable
     */
    public function __invoke(string $token): Response|JsonResponse
    {
        try {
            $this->emailConfirmationService->confirmEmailAddressFromLink($token);
        } catch (EncryptedPayloadExpiredException) {
            return $this->errorResponse(__('The email confirmation link has expired. Please request a new one.'));
        } catch (DecryptionFailedException|ResourceNotFoundException) {
            return $this->errorResponse(__('The email confirmation link is invalid.'));
        }

        return $this->noContentResponse();
    }
}
