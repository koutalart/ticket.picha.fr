<?php

namespace HiEvents\Console\Commands;

use HiEvents\DomainObjects\AccountUserDomainObject;
use HiEvents\DomainObjects\Enums\Role;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventViewerDomainObject;
use HiEvents\DomainObjects\Status\EventViewerStatus;
use HiEvents\DomainObjects\UserDomainObject;
use HiEvents\Repository\Interfaces\AccountUserRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\EventViewerRepositoryInterface;
use HiEvents\Repository\Interfaces\UserRepositoryInterface;
use Illuminate\Console\Command;

class EventViewerGrantCommand extends Command
{
    protected $signature = 'event-viewer:grant
        {event : Event ID}
        {email : E-mail of an existing user of the event\'s account}
        {--revoke : Remove the read-only access to this event}';

    protected $description = 'Give a user read-only access to one event (participants, orders, statistics, exports)';

    public function handle(
        EventRepositoryInterface $eventRepository,
        UserRepositoryInterface $userRepository,
        AccountUserRepositoryInterface $accountUserRepository,
        EventViewerRepositoryInterface $eventViewerRepository,
    ): int {
        /** @var EventDomainObject|null $event */
        $event = $eventRepository->findFirst((int) $this->argument('event'));
        if ($event === null) {
            $this->error(__('Event not found.'));

            return self::FAILURE;
        }

        /** @var UserDomainObject|null $user */
        $user = $userRepository->findFirstWhere(['email' => strtolower(trim((string) $this->argument('email')))]);
        if ($user === null) {
            $this->error(__('User not found.'));

            return self::FAILURE;
        }

        /** @var AccountUserDomainObject|null $accountUser */
        $accountUser = $accountUserRepository->findFirstWhere([
            'account_id' => $event->getAccountId(),
            'user_id' => $user->getId(),
        ]);
        if ($accountUser === null) {
            $this->error(__('This user is not a member of the event\'s account.'));

            return self::FAILURE;
        }

        if (in_array($accountUser->getRole(), [Role::SUPERADMIN->name, Role::ADMIN->name], true)) {
            $this->error(__('An administrator cannot be made read-only.'));

            return self::FAILURE;
        }

        /** @var EventViewerDomainObject|null $viewer */
        $viewer = $eventViewerRepository->findFirstWhere([
            'event_id' => $event->getId(),
            'user_id' => $user->getId(),
        ]);

        if ($this->option('revoke')) {
            if ($viewer !== null) {
                $eventViewerRepository->updateFromArray($viewer->getId(), ['status' => EventViewerStatus::REVOKED->name]);
            }
            $this->info(__('Read-only access removed for :email on event #:event.', [
                'email' => $user->getEmail(),
                'event' => $event->getId(),
            ]));

            return self::SUCCESS;
        }

        if ($accountUser->getRole() !== Role::EVENT_VIEWER->name) {
            $accountUserRepository->updateFromArray($accountUser->getId(), ['role' => Role::EVENT_VIEWER->name]);
            $this->info(__('Role changed from :from to :to.', [
                'from' => $accountUser->getRole(),
                'to' => Role::EVENT_VIEWER->name,
            ]));
        }

        if ($viewer === null) {
            $eventViewerRepository->create([
                'event_id' => $event->getId(),
                'user_id' => $user->getId(),
                'status' => EventViewerStatus::ACTIVE->name,
            ]);
        } else {
            $eventViewerRepository->updateFromArray($viewer->getId(), ['status' => EventViewerStatus::ACTIVE->name]);
        }

        $this->info(__('Read-only access given to :email on event #:event (:title).', [
            'email' => $user->getEmail(),
            'event' => $event->getId(),
            'title' => $event->getTitle(),
        ]));

        return self::SUCCESS;
    }
}
