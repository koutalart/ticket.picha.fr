<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\BoxOffice;

use HiEvents\DomainObjects\Enums\Role;
use HiEvents\DomainObjects\EventBoxOfficeOperatorDomainObject;
use HiEvents\DomainObjects\Generated\EventBoxOfficeOperatorDomainObjectAbstract;
use HiEvents\DomainObjects\Status\BoxOfficeOperatorStatus;
use HiEvents\DomainObjects\Status\UserStatus;
use HiEvents\DomainObjects\UserDomainObject;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Repository\Interfaces\AccountRepositoryInterface;
use HiEvents\Repository\Interfaces\AccountUserRepositoryInterface;
use HiEvents\Repository\Interfaces\EventBoxOfficeOperatorRepositoryInterface;
use HiEvents\Repository\Interfaces\UserRepositoryInterface;
use HiEvents\Services\Application\Handlers\BoxOffice\DTO\CreateBoxOfficeOperatorDTO;
use HiEvents\Services\Domain\Account\AccountUserAssociationService;
use HiEvents\Services\Domain\User\SendUserInvitationService;
use Illuminate\Database\DatabaseManager;
use Throwable;

/**
 * D23 (PICHA_KIOSK_V2_DECISIONS.md) — create/assign a box office operator for
 * one event. ADMIN-only (enforced in the action). Reuses the existing user
 * invitation machinery unchanged:
 *
 *  - new email  -> users (INVITED) + account_users (BOX_OFFICE_OPERATOR,
 *    INVITED) + UserInvited mail, then the event assignment row;
 *  - an email already on this account as an operator -> no new user, no mail,
 *    just the (new or re-activated) event assignment row;
 *  - an email already on this account with another role -> conflict.
 */
class CreateBoxOfficeOperatorHandler
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        private readonly AccountRepositoryInterface $accountRepository,
        private readonly AccountUserRepositoryInterface $accountUserRepository,
        private readonly EventBoxOfficeOperatorRepositoryInterface $operatorRepository,
        private readonly AccountUserAssociationService $accountUserAssociationService,
        private readonly SendUserInvitationService $sendUserInvitationService,
        private readonly DatabaseManager $databaseManager,
    ) {}

    /**
     * @throws ResourceConflictException
     * @throws Throwable
     */
    public function handle(CreateBoxOfficeOperatorDTO $dto): EventBoxOfficeOperatorDomainObject
    {
        return $this->databaseManager->transaction(function () use ($dto) {
            $user = $this->resolveOperatorUser($dto);

            return $this->assignToEvent($dto, $user->getId());
        });
    }

    /**
     * @throws ResourceConflictException
     */
    private function resolveOperatorUser(CreateBoxOfficeOperatorDTO $dto): UserDomainObject
    {
        $existingUser = $this->userRepository->findFirstWhere(['email' => strtolower($dto->email)]);

        if ($existingUser === null) {
            return $this->createInvitedOperator($dto);
        }

        $accountUser = $this->accountUserRepository->findFirstWhere([
            'user_id' => $existingUser->getId(),
            'account_id' => $dto->account_id,
        ]);

        if ($accountUser === null) {
            $existingUser->setCurrentAccountUser($this->accountUserAssociationService->associate(
                user: $existingUser,
                account: $this->accountRepository->findById($dto->account_id),
                role: Role::BOX_OFFICE_OPERATOR,
                status: UserStatus::INVITED,
                invitedByUserId: $dto->created_by_user_id,
            ));

            $this->sendUserInvitationService->sendInvitation($existingUser, $dto->account_id);

            return $existingUser;
        }

        if ($accountUser->getRole() !== Role::BOX_OFFICE_OPERATOR->name) {
            throw new ResourceConflictException(
                __('The email :email already belongs to a user on this account who is not a box office operator.', [
                    'email' => $dto->email,
                ])
            );
        }

        return $existingUser;
    }

    private function createInvitedOperator(CreateBoxOfficeOperatorDTO $dto): UserDomainObject
    {
        $account = $this->accountRepository->findById($dto->account_id);

        $user = $this->userRepository->create([
            'first_name' => $dto->first_name,
            'last_name' => $dto->last_name,
            'email' => strtolower($dto->email),
            'password' => 'invited',
            'timezone' => $account->getTimezone(),
        ]);

        $user->setCurrentAccountUser($this->accountUserAssociationService->associate(
            user: $user,
            account: $account,
            role: Role::BOX_OFFICE_OPERATOR,
            status: UserStatus::INVITED,
            invitedByUserId: $dto->created_by_user_id,
        ));

        $this->sendUserInvitationService->sendInvitation($user, $dto->account_id);

        return $user;
    }

    private function assignToEvent(CreateBoxOfficeOperatorDTO $dto, int $userId): EventBoxOfficeOperatorDomainObject
    {
        $existing = $this->operatorRepository->findFirstWhere([
            EventBoxOfficeOperatorDomainObjectAbstract::EVENT_ID => $dto->event_id,
            EventBoxOfficeOperatorDomainObjectAbstract::USER_ID => $userId,
        ]);

        if ($existing !== null) {
            if ($existing->getStatus() === BoxOfficeOperatorStatus::ACTIVE->name) {
                return $existing;
            }

            return $this->operatorRepository->updateFromArray($existing->getId(), [
                EventBoxOfficeOperatorDomainObjectAbstract::STATUS => BoxOfficeOperatorStatus::ACTIVE->name,
            ]);
        }

        return $this->operatorRepository->create([
            EventBoxOfficeOperatorDomainObjectAbstract::EVENT_ID => $dto->event_id,
            EventBoxOfficeOperatorDomainObjectAbstract::USER_ID => $userId,
            EventBoxOfficeOperatorDomainObjectAbstract::CREATED_BY_USER_ID => $dto->created_by_user_id,
            EventBoxOfficeOperatorDomainObjectAbstract::STATUS => BoxOfficeOperatorStatus::ACTIVE->name,
        ]);
    }
}
