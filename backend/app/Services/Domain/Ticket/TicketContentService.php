<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Ticket;

use Carbon\Carbon;
use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\Generated\ImageDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\Repository\Interfaces\ImageRepositoryInterface;
use HiEvents\Services\Domain\Ticket\DTO\TicketContentDTO;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Str;

class TicketContentService
{
    public function __construct(
        private readonly ImageRepositoryInterface $imageRepository,
        private readonly FilesystemManager $filesystemManager,
    ) {}

    public function build(
        AttendeeDomainObject $attendee,
        EventDomainObject $event,
        ?EventSettingDomainObject $eventSettings,
        ?OrganizerDomainObject $organizer,
        ?ProductDomainObject $product,
    ): TicketContentDTO {
        $when = '';
        $hours = '';
        if ($event->getStartDate()) {
            $timezone = $event->getTimezone() ?: 'UTC';
            $start = Carbon::parse($event->getStartDate(), 'UTC')->timezone($timezone)->locale(app()->getLocale());
            $end = $event->getEndDate()
                ? Carbon::parse($event->getEndDate(), 'UTC')->timezone($timezone)
                : null;
            $when = Str::ucfirst($start->isoFormat('ddd D MMM YYYY'));
            $hours = $this->formatHours($start, $end);
        }

        [$venueName, $venueCity] = $this->venue($event, $eventSettings);

        return new TicketContentDTO(
            public_id: (string) $attendee->getPublicId(),
            event_title: (string) $event->getTitle(),
            product_title: (string) $product?->getTitle(),
            attendee_name: trim($attendee->getFirstName().' '.$attendee->getLastName()),
            event_when: $when,
            event_hours: $hours,
            venue_name: $venueName,
            venue_city: $venueCity,
            organizer_name: (string) $organizer?->getName(),
            sponsor_name: (string) $eventSettings?->getTicketSponsorName(),
            event_logo_image: $this->eventImage($event->getId(), ImageType::TICKET_LOGO),
            sponsor_logo_image: $this->eventImage($event->getId(), ImageType::TICKET_SPONSOR_LOGO),
        );
    }

    private function formatHours(Carbon $start, ?Carbon $end): string
    {
        $from = $start->format('H\hi');
        if ($end === null) {
            return $from;
        }

        return $from.' - '.$end->format('H\hi');
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function venue(EventDomainObject $event, ?EventSettingDomainObject $settings): array
    {
        $details = $this->locationDetails($event->getLocationDetails());
        $venue = trim((string) ($details['venue_name'] ?? $event->getLocation() ?? ''));
        $city = trim((string) ($details['city'] ?? ''));

        if ($venue === '' && $city === '') {
            $settingsDetails = $this->locationDetails($settings?->getLocationDetails());
            $venue = trim((string) ($settingsDetails['venue_name'] ?? ''));
            $city = trim((string) ($settingsDetails['city'] ?? ''));
        }

        return [$venue, $city];
    }

    /**
     * @return array<string, mixed>
     */
    private function locationDetails(mixed $details): array
    {
        if (is_string($details)) {
            $details = json_decode($details, true);
        }

        return is_array($details) ? $details : [];
    }

    private function eventImage(?int $eventId, ImageType $type): ?string
    {
        if ($eventId === null) {
            return null;
        }

        $image = $this->imageRepository->findFirstWhere([
            ImageDomainObjectAbstract::ENTITY_ID => $eventId,
            ImageDomainObjectAbstract::ENTITY_TYPE => EventDomainObject::class,
            ImageDomainObjectAbstract::TYPE => $type->name,
        ]);
        if ($image === null) {
            return null;
        }

        return $this->filesystemManager->disk($image->getDisk())->get($image->getPath());
    }
}
