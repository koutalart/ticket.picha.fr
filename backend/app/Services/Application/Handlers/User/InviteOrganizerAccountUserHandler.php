<?php

namespace HiEvents\Services\Application\Handlers\User;

use HiEvents\DomainObjects\AccountDomainObject;
use HiEvents\DomainObjects\AccountUserDomainObject;
use HiEvents\DomainObjects\Enums\AccountInvitationOutcome;
use HiEvents\DomainObjects\Enums\Role;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\Status\UserStatus;
use HiEvents\DomainObjects\UserDomainObject;
use HiEvents\Exceptions\AccountInviterNotFoundException;
use HiEvents\Exceptions\InvitationRoleNotAllowedException;
use HiEvents\Exceptions\OrganizerNotFoundException;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Repository\Eloquent\Value\OrderAndDirection;
use HiEvents\Repository\Interfaces\AccountRepositoryInterface;
use HiEvents\Repository\Interfaces\AccountUserRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Repository\Interfaces\UserRepositoryInterface;
use HiEvents\Services\Application\Handlers\User\DTO\CreateUserDTO;
use HiEvents\Services\Application\Handlers\User\DTO\InviteOrganizerAccountUserDTO;
use HiEvents\Services\Application\Handlers\User\DTO\OrganizerAccountInvitationResultDTO;
use HiEvents\Services\Domain\User\SendUserInvitationService;
use Throwable;

/**
 * Sends the same invitation as Settings > Users > Invite, on behalf of the account
 * the organizer belongs to. The account owner is the inviter; when the account has
 * no owner row, the oldest active admin of the account is used instead.
 */
readonly class InviteOrganizerAccountUserHandler
{
    public function __construct(
        private OrganizerRepositoryInterface $organizerRepository,
        private AccountRepositoryInterface $accountRepository,
        private AccountUserRepositoryInterface $accountUserRepository,
        private UserRepositoryInterface $userRepository,
        private CreateUserHandler $createUserHandler,
        private SendUserInvitationService $sendUserInvitationService,
    ) {}

    /**
     * @throws OrganizerNotFoundException
     * @throws InvitationRoleNotAllowedException
     * @throws AccountInviterNotFoundException
     * @throws ResourceConflictException
     * @throws Throwable
     */
    public function handle(InviteOrganizerAccountUserDTO $data): OrganizerAccountInvitationResultDTO
    {
        $this->assertRoleAllowed($data->role, $data->allow_admin);

        $email = strtolower(trim($data->email));

        /** @var OrganizerDomainObject|null $organizer */
        $organizer = $this->organizerRepository->findFirstWhere(['id' => $data->organizer_id]);

        if ($organizer === null) {
            throw new OrganizerNotFoundException(
                __('Organizer :id not found', ['id' => $data->organizer_id])
            );
        }

        /** @var AccountDomainObject $account */
        $account = $this->accountRepository->findById($organizer->getAccountId());

        [$inviter, $inviterIsOwner] = $this->findInviter($account->getId());

        $result = fn (AccountInvitationOutcome $outcome, ?int $userId = null, ?AccountUserDomainObject $membership = null) => new OrganizerAccountInvitationResultDTO(
            outcome: $outcome,
            account_id: $account->getId(),
            account_name: $account->getName(),
            organizer_id: $organizer->getId(),
            organizer_name: $organizer->getName(),
            email: $email,
            role: $data->role,
            inviter_id: $inviter->getId(),
            inviter_email: $inviter->getEmail(),
            inviter_is_owner: $inviterIsOwner,
            user_id: $userId,
            member_status: $membership?->getStatus(),
            member_role: $membership?->getRole(),
        );

        /** @var UserDomainObject|null $existingUser */
        $existingUser = $this->userRepository->findFirstWhere(['email' => $email]);

        /** @var AccountUserDomainObject|null $membership */
        $membership = $existingUser === null ? null : $this->accountUserRepository->findFirstWhere([
            'account_id' => $account->getId(),
            'user_id' => $existingUser->getId(),
        ]);

        if ($membership !== null && $membership->getStatus() !== UserStatus::INVITED->name) {
            return $result(AccountInvitationOutcome::ALREADY_MEMBER, $existingUser->getId(), $membership);
        }

        if ($membership !== null) {
            if (! $data->resend) {
                return $result(AccountInvitationOutcome::ALREADY_INVITED, $existingUser->getId(), $membership);
            }

            if ($data->dry_run) {
                return $result(AccountInvitationOutcome::DRY_RUN, $existingUser->getId(), $membership);
            }

            $this->sendUserInvitationService->sendInvitation($existingUser, $account->getId());

            return $result(AccountInvitationOutcome::INVITATION_RESENT, $existingUser->getId(), $membership);
        }

        if ($data->dry_run) {
            return $result(AccountInvitationOutcome::DRY_RUN, $existingUser?->getId());
        }

        $invitedUser = $this->createUserHandler->handle(new CreateUserDTO(
            first_name: $this->firstName($data->first_name, $email),
            last_name: $data->last_name !== null && trim($data->last_name) !== '' ? trim($data->last_name) : null,
            email: $email,
            invited_by: $inviter->getId(),
            account_id: $account->getId(),
            role: $data->role,
        ));

        return $result(AccountInvitationOutcome::INVITED, $invitedUser->getId(), $invitedUser->getCurrentAccountUser());
    }

    /**
     * @return Role[]
     */
    public static function allowedRoles(bool $allowAdmin): array
    {
        return array_values(array_filter(
            array_map(static fn (string $role) => Role::from($role), Role::getAssignableRoles()),
            static fn (Role $role) => $allowAdmin || $role !== Role::ADMIN,
        ));
    }

    /**
     * @throws InvitationRoleNotAllowedException
     */
    private function assertRoleAllowed(Role $role, bool $allowAdmin): void
    {
        if ($role === Role::ADMIN && ! $allowAdmin) {
            throw new InvitationRoleNotAllowedException(
                __('The ADMIN role requires the --allow-admin option')
            );
        }

        if (! in_array($role, self::allowedRoles($allowAdmin), true)) {
            throw new InvitationRoleNotAllowedException(
                __('The :role role cannot be given through an invitation', ['role' => $role->value])
            );
        }
    }

    /**
     * @return array{0: UserDomainObject, 1: bool}
     *
     * @throws AccountInviterNotFoundException
     */
    private function findInviter(int $accountId): array
    {
        /** @var AccountUserDomainObject|null $owner */
        $owner = $this->accountUserRepository->findFirstWhere([
            'account_id' => $accountId,
            'is_account_owner' => true,
        ]);

        $inviterRow = $owner ?? $this->accountUserRepository->findWhere(
            where: [
                'account_id' => $accountId,
                'role' => Role::ADMIN->name,
                'status' => UserStatus::ACTIVE->name,
            ],
            orderAndDirections: [new OrderAndDirection('id')],
        )->first();

        /** @var UserDomainObject|null $inviter */
        $inviter = $inviterRow === null ? null : $this->userRepository->findFirstWhere(['id' => $inviterRow->getUserId()]);

        if ($inviter === null) {
            throw new AccountInviterNotFoundException(
                __('No owner or active admin found for account :id', ['id' => $accountId])
            );
        }

        return [$inviter, $owner !== null];
    }

    private function firstName(?string $firstName, string $email): string
    {
        $firstName = $firstName === null ? '' : trim($firstName);

        return $firstName !== '' ? $firstName : strstr($email, '@', true);
    }
}
