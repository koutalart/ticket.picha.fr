<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\PublicUrl;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\Status\OrganizerStatus;
use HiEvents\Helper\CustomDomainHelper;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;

/**
 * Base URL of every link aimed at participants: the organizer's own domain when it has one, the platform otherwise.
 */
class PublicBaseUrlResolver
{
    /** @var array<int, string|null> */
    private array $domainByOrganizerId = [];

    /** @var array<int, int|null> */
    private array $organizerIdByEventId = [];

    public function __construct(
        private readonly OrganizerRepositoryInterface $organizerRepository,
        private readonly EventRepositoryInterface $eventRepository,
    ) {}

    public static function activeCustomDomain(?OrganizerDomainObject $organizer): ?string
    {
        if ($organizer === null || $organizer->getStatus() === OrganizerStatus::ARCHIVED->name) {
            return null;
        }

        $domain = CustomDomainHelper::normalize($organizer->getCustomDomain());

        return $domain !== null && CustomDomainHelper::isValid($domain) ? $domain : null;
    }

    public function platformUrl(): string
    {
        return rtrim((string) config('app.frontend_url'), '/');
    }

    public function forOrganizer(?OrganizerDomainObject $organizer): string
    {
        if ($organizer !== null && $organizer->getId() !== null) {
            $this->domainByOrganizerId[$organizer->getId()] = self::activeCustomDomain($organizer);
        }

        return $this->baseUrlForDomain(self::activeCustomDomain($organizer));
    }

    public function forOrganizerId(?int $organizerId): string
    {
        if ($organizerId === null) {
            return $this->platformUrl();
        }

        if (! array_key_exists($organizerId, $this->domainByOrganizerId)) {
            /** @var OrganizerDomainObject|null $organizer */
            $organizer = $this->organizerRepository->findFirst($organizerId);
            $this->domainByOrganizerId[$organizerId] = self::activeCustomDomain($organizer);
        }

        return $this->baseUrlForDomain($this->domainByOrganizerId[$organizerId]);
    }

    public function forEvent(?EventDomainObject $event): string
    {
        if ($event === null) {
            return $this->platformUrl();
        }

        if ($event->getOrganizer() !== null) {
            return $this->forOrganizer($event->getOrganizer());
        }

        return $this->forOrganizerId($event->getOrganizerId());
    }

    public function forEventId(?int $eventId): string
    {
        if ($eventId === null) {
            return $this->platformUrl();
        }

        if (! array_key_exists($eventId, $this->organizerIdByEventId)) {
            /** @var EventDomainObject|null $event */
            $event = $this->eventRepository->findFirst($eventId);
            $this->organizerIdByEventId[$eventId] = $event?->getOrganizerId();
        }

        return $this->forOrganizerId($this->organizerIdByEventId[$eventId]);
    }

    private function baseUrlForDomain(?string $domain): string
    {
        return $domain !== null ? 'https://'.$domain : $this->platformUrl();
    }
}
