<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Assistant;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\AssistantActionRefusedException;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Services\Domain\Assistant\DTO\AssistantScopeDTO;

/**
 * The assistant always names the organizer it works for: an event of another organizer is refused.
 */
class AssistantScopeResolver
{
    public function __construct(
        private readonly OrganizerRepositoryInterface $organizerRepository,
        private readonly EventRepositoryInterface $eventRepository,
    ) {}

    /**
     * @throws AssistantActionRefusedException
     */
    public function resolve(int $organizerId, ?int $eventId): AssistantScopeDTO
    {
        /** @var OrganizerDomainObject|null $organizer */
        $organizer = $this->organizerRepository->findFirstWhere(['id' => $organizerId]);

        if ($organizer === null) {
            throw new AssistantActionRefusedException(__('Organizer :id not found.', ['id' => $organizerId]));
        }

        if ($eventId === null) {
            return new AssistantScopeDTO(organizer: $organizer);
        }

        /** @var EventDomainObject|null $event */
        $event = $this->eventRepository->findFirstWhere(['id' => $eventId]);

        if ($event === null
            || $event->getOrganizerId() !== $organizer->getId()
            || $event->getAccountId() !== $organizer->getAccountId()) {
            throw new AssistantActionRefusedException(__('Event :event does not belong to organizer :organizer.', [
                'event' => $eventId,
                'organizer' => $organizerId,
            ]));
        }

        return new AssistantScopeDTO(organizer: $organizer, event: $event);
    }
}
