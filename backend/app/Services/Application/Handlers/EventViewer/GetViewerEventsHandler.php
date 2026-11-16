<?php

namespace HiEvents\Services\Application\Handlers\EventViewer;

use HiEvents\DomainObjects\Enums\Role;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventViewerDomainObject;
use HiEvents\DomainObjects\Generated\EventDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\EventViewerDomainObjectAbstract;
use HiEvents\DomainObjects\Status\EventViewerStatus;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\EventViewerRepositoryInterface;

class GetViewerEventsHandler
{
    public function __construct(
        private readonly EventViewerRepositoryInterface $eventViewerRepository,
        private readonly EventRepositoryInterface $eventRepository,
    ) {}

    public function handle(int $userId, string $role, int $accountId): array
    {
        if ($role !== Role::EVENT_VIEWER->name) {
            return [];
        }

        $eventIds = $this->eventViewerRepository
            ->findWhere([
                EventViewerDomainObjectAbstract::USER_ID => $userId,
                EventViewerDomainObjectAbstract::STATUS => EventViewerStatus::ACTIVE->name,
            ])
            ->map(fn (EventViewerDomainObject $viewer) => $viewer->getEventId())
            ->all();

        if ($eventIds === []) {
            return [];
        }

        return $this->eventRepository
            ->findWhereIn(EventDomainObjectAbstract::ID, $eventIds)
            ->filter(fn (EventDomainObject $event) => $event->getAccountId() === $accountId)
            ->sortBy(fn (EventDomainObject $event) => $event->getStartDate())
            ->map(fn (EventDomainObject $event) => [
                'id' => $event->getId(),
                'title' => $event->getTitle(),
                'start_date' => $event->getStartDate(),
                'timezone' => $event->getTimezone(),
            ])
            ->values()
            ->all();
    }
}
