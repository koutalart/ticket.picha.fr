<?php

namespace HiEvents\Services\Infrastructure\Authorization;

use HiEvents\DomainObjects\AccountDomainObject;
use HiEvents\DomainObjects\Enums\Role;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\ImageDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\Status\BoxOfficeOperatorStatus;
use HiEvents\DomainObjects\Status\EventViewerStatus;
use HiEvents\DomainObjects\Status\UserStatus;
use HiEvents\DomainObjects\TaxAndFeesDomainObject;
use HiEvents\DomainObjects\UserDomainObject;
use HiEvents\Exceptions\UnauthorizedException;
use HiEvents\Repository\Interfaces\AccountRepositoryInterface;
use HiEvents\Repository\Interfaces\AccountUserRepositoryInterface;
use HiEvents\Repository\Interfaces\EventBoxOfficeOperatorRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\EventViewerRepositoryInterface;
use HiEvents\Repository\Interfaces\ImageRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Repository\Interfaces\TaxAndFeeRepositoryInterface;
use HiEvents\Repository\Interfaces\UserRepositoryInterface;
use Illuminate\Auth\AuthManager;
use Illuminate\Foundation\Application;

readonly class IsAuthorizedService
{
    private const EVENT_VIEWER_ALLOWED_REQUESTS = [
        'GET' => ['', '/stats', '/attendees', '/orders'],
        'POST' => ['/attendees/export', '/orders/export'],
    ];

    public function __construct(
        private Application $app,
        private AccountUserRepositoryInterface $accountUserRepository,
        private AuthManager $auth,
    ) {}

    /**
     * @todo This is a very simplistic way of handling roles. Currently we have an ADMIN and ORGANIZER role, but we
     *      will have a more granular approach to roles.
     */
    public function validateUserRole(Role $minimumRole, UserDomainObject $authUser): void
    {
        // PICHA Kiosk v2 (D23): a BOX_OFFICE_OPERATOR is never allowed through
        // the generic role floor. Its only access is granted by
        // validateBoxOfficeEventScope() on the three box office endpoints;
        // every other isActionAuthorized() / minimumAllowedRole() call site is
        // closed here in one place.
        if ($authUser->getCurrentAccountUser()?->getRole() === Role::BOX_OFFICE_OPERATOR->name
            && $minimumRole !== Role::BOX_OFFICE_OPERATOR
        ) {
            throw new UnauthorizedException(__('You are not authorized to perform this action.'));
        }

        if ($authUser->getCurrentAccountUser()?->getRole() === Role::EVENT_VIEWER->name
            && $minimumRole !== Role::EVENT_VIEWER
        ) {
            throw new UnauthorizedException(__('You are not authorized to perform this action.'));
        }

        if ($minimumRole === Role::ADMIN
            && in_array($authUser->getCurrentAccountUser()->getRole(), [Role::SUPERADMIN->name, Role::ADMIN->name], true) === false
        ) {
            throw new UnauthorizedException(__('You are not authorized to perform this action.'));
        }

        if ($minimumRole === Role::SUPERADMIN && $authUser->getCurrentAccountUser()->getRole() !== Role::SUPERADMIN->name) {
            throw new UnauthorizedException(__('You are not authorized to perform this action.'));
        }
    }

    /**
     * PICHA Kiosk v2 (D23) — authorization for the three box office endpoints,
     * used instead of the generic isActionAuthorized():
     *
     *  - SUPERADMIN / ADMIN / ORGANIZER: same rule as everywhere else — the
     *    event must belong to their account, no per-event restriction.
     *  - BOX_OFFICE_OPERATOR: must hold an ACTIVE row in
     *    event_box_office_operators for this exact event. A REVOKED row, a
     *    missing row, or an event of another account all fail.
     *
     * @throws UnauthorizedException
     */
    public function validateBoxOfficeEventScope(int $eventId, UserDomainObject $authUser): void
    {
        $this->validateUserStatus($authUser);

        $accountUser = $authUser->getCurrentAccountUser();
        $role = $accountUser?->getRole();
        $accountId = $accountUser?->getAccountId();

        if ($accountId === null) {
            throw new UnauthorizedException;
        }

        $event = $this->app->make(EventRepositoryInterface::class)->findById($eventId);

        if ($event?->getAccountId() !== $accountId) {
            throw new UnauthorizedException;
        }

        if (in_array($role, [Role::SUPERADMIN->name, Role::ADMIN->name, Role::ORGANIZER->name], true)) {
            return;
        }

        if ($role === Role::BOX_OFFICE_OPERATOR->name) {
            $assignment = $this->app->make(EventBoxOfficeOperatorRepositoryInterface::class)->findFirstWhere([
                'event_id' => $eventId,
                'user_id' => $authUser->getId(),
                'status' => BoxOfficeOperatorStatus::ACTIVE->name,
            ]);

            if ($assignment !== null) {
                return;
            }
        }

        throw new UnauthorizedException;
    }

    public function isActionAuthorized(
        int $entityId,
        string $entityType,
        UserDomainObject $authUser,
        int $authAccountId,
        Role $minimumRole
    ): void {
        $this->validateUserStatus($authUser);

        if ($authUser->getCurrentAccountUser()?->getRole() === Role::EVENT_VIEWER->name) {
            $this->validateEventViewerAccess($entityId, $entityType, $authUser, $authAccountId);

            return;
        }

        $this->validateUserRole($minimumRole, $authUser);

        $repository = match ($entityType) {
            EventDomainObject::class => $this->app->make(EventRepositoryInterface::class),
            AccountDomainObject::class => $this->app->make(AccountRepositoryInterface::class),
            UserDomainObject::class => $this->app->make(UserRepositoryInterface::class),
            TaxAndFeesDomainObject::class => $this->app->make(TaxAndFeeRepositoryInterface::class),
            OrganizerDomainObject::class => $this->app->make(OrganizerRepositoryInterface::class),
            ImageDomainObject::class => $this->app->make(ImageRepositoryInterface::class),
        };

        $entity = $repository->findById($entityId);

        $result = match ($entityType) {
            EventDomainObject::class,
            ImageDomainObject::class,
            OrganizerDomainObject::class => $entity?->getAccountId() === $authAccountId,
            AccountDomainObject::class => $entity?->getId() === $authAccountId,
            UserDomainObject::class => $this->validateUserUpdate($entity, $authAccountId),
            TaxAndFeesDomainObject::class => $this->validateTax($entity, $authAccountId),
        };

        if (! $result) {
            throw new UnauthorizedException;
        }
    }

    /**
     * @throws UnauthorizedException
     */
    private function validateEventViewerAccess(
        int $entityId,
        string $entityType,
        UserDomainObject $authUser,
        int $authAccountId,
    ): void {
        if ($entityType !== EventDomainObject::class || ! $this->isEventViewerRequestAllowed($entityId)) {
            throw new UnauthorizedException(__('You are not authorized to perform this action.'));
        }

        $event = $this->app->make(EventRepositoryInterface::class)->findById($entityId);

        if ($event?->getAccountId() !== $authAccountId) {
            throw new UnauthorizedException;
        }

        $assignment = $this->app->make(EventViewerRepositoryInterface::class)->findFirstWhere([
            'event_id' => $entityId,
            'user_id' => $authUser->getId(),
            'status' => EventViewerStatus::ACTIVE->name,
        ]);

        if ($assignment === null) {
            throw new UnauthorizedException;
        }
    }

    private function isEventViewerRequestAllowed(int $eventId): bool
    {
        $request = $this->app->make('request');
        $allowedSuffixes = self::EVENT_VIEWER_ALLOWED_REQUESTS[strtoupper($request->method())] ?? [];

        if (! preg_match('~^(?:.*/)?events/(\d+)(/.*)?$~', trim($request->path(), '/'), $matches)) {
            return false;
        }

        if ((int) $matches[1] !== $eventId) {
            return false;
        }

        return in_array(rtrim($matches[2] ?? '', '/'), $allowedSuffixes, true);
    }

    private function validateUserUpdate(?UserDomainObject $user, int $authAccountId): bool
    {
        if ($user === null) {
            return false;
        }

        $accountUser = $this->accountUserRepository->findFirstWhere([
            'account_id' => $authAccountId,
            'user_id' => $user->getId(),
        ]);

        return $accountUser !== null;
    }

    private function validateTax(?TaxAndFeesDomainObject $taxOrFee, int $authAccountId): bool
    {
        if ($taxOrFee === null) {
            return false;
        }

        if ($taxOrFee->getAccountId() === $authAccountId) {
            return true;
        }

        return false;
    }

    private function validateUserStatus(UserDomainObject $authUser): void
    {
        if ($authUser->getCurrentAccountUser()?->getStatus() !== UserStatus::ACTIVE->name) {
            // Log the user out if their account is not active. This can happen if a user is
            // deactivated while they are logged in.
            $this->auth->logout();
            throw new UnauthorizedException(__('Your account is not active.'));
        }
    }
}
