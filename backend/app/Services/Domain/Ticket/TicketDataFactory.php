<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Ticket;

use Carbon\Carbon;
use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\ImageDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Services\Domain\Ticket\DTO\TicketDataDTO;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Builds the data shared by every ticket format (Zebra label, PDF, on-screen ticket).
 */
class TicketDataFactory
{
    public function __construct(
        private readonly EventRepositoryInterface $eventRepository,
        private readonly EventSettingsRepositoryInterface $eventSettingsRepository,
        private readonly OrganizerRepositoryInterface $organizerRepository,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly FilesystemManager $filesystemManager,
        private readonly LoggerInterface $logger,
    ) {}

    public function forAttendee(AttendeeDomainObject $attendee): TicketDataDTO
    {
        [$event, $organizer] = $this->loadEvent($attendee->getEventId());
        $product = $attendee->getProduct()
            ?? $this->productRepository->findById($attendee->getProductId());

        return $this->build(
            event: $event,
            organizer: $organizer,
            publicId: $attendee->getPublicId(),
            productTitle: $product->getTitle(),
            attendeeName: trim($attendee->getFirstName().' '.$attendee->getLastName()),
            status: match ($attendee->getStatus()) {
                AttendeeStatus::CANCELLED->name => TicketDataDTO::STATUS_CANCELLED,
                AttendeeStatus::AWAITING_PAYMENT->name => TicketDataDTO::STATUS_AWAITING_PAYMENT,
                default => TicketDataDTO::STATUS_VALID,
            },
        );
    }

    public function forPreview(int $eventId): TicketDataDTO
    {
        [$event, $organizer] = $this->loadEvent($eventId);
        $product = $this->productRepository->findFirstWhere(['event_id' => $eventId]);

        return $this->build(
            event: $event,
            organizer: $organizer,
            publicId: 'A-APERCU00',
            productTitle: $product?->getTitle() ?? __('Ticket'),
            attendeeName: 'Jean Dupont',
            status: TicketDataDTO::STATUS_VALID,
        );
    }

    /**
     * @return array{0: EventDomainObject, 1: ?OrganizerDomainObject}
     */
    private function loadEvent(int $eventId): array
    {
        $event = $this->eventRepository
            ->loadRelation(ImageDomainObject::class)
            ->findById($eventId);

        $organizer = $event->getOrganizerId()
            ? $this->organizerRepository
                ->loadRelation(ImageDomainObject::class)
                ->findById($event->getOrganizerId())
            : null;

        return [$event, $organizer];
    }

    private function build(
        EventDomainObject $event,
        ?OrganizerDomainObject $organizer,
        string $publicId,
        string $productTitle,
        string $attendeeName,
        string $status,
    ): TicketDataDTO {
        $settings = $this->eventSettingsRepository->findFirstWhere(['event_id' => $event->getId()]);
        [$date, $time] = $this->eventDateAndTime($event, $this->dateDisplayMode($settings?->getTicketDesignSettings()));

        return new TicketDataDTO(
            publicId: $publicId,
            eventTitle: $event->getTitle(),
            productTitle: $this->stripEmoji($productTitle),
            attendeeName: $attendeeName,
            eventDate: $date,
            eventTime: $time,
            venue: $this->eventVenue($event, $settings?->getLocationDetails()),
            sellerName: $organizer?->getName() ?? '',
            organizerLogo: $this->readImage($event->getImages(), ImageType::TICKET_LOGO)
                ?? $this->readImage($organizer?->getImages(), ImageType::ORGANIZER_LOGO),
            sponsorLogo: $this->readImage($event->getImages(), ImageType::TICKET_SPONSOR_LOGO),
            sponsorName: $this->sponsorName($settings?->getTicketDesignSettings()),
            status: $status,
        );
    }

    private function sponsorName(mixed $designSettings): string
    {
        if (is_string($designSettings)) {
            $designSettings = json_decode($designSettings, true);
        }

        return is_array($designSettings) ? trim((string) ($designSettings['sponsor_name'] ?? '')) : '';
    }

    private function dateDisplayMode(mixed $designSettings): string
    {
        if (is_string($designSettings)) {
            $designSettings = json_decode($designSettings, true);
        }

        return is_array($designSettings) ? (string) ($designSettings['date_display_mode'] ?? '') : '';
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function eventDateAndTime(EventDomainObject $event, string $dateDisplayMode): array
    {
        if (! $event->getStartDate() || $dateDisplayMode === 'HIDDEN') {
            return ['', ''];
        }

        $locale = app()->getLocale();
        $start = Carbon::parse($event->getStartDate())
            ->timezone($event->getTimezone() ?: 'UTC')
            ->locale($locale);

        return [
            ucfirst($start->isoFormat('ddd D MMM YYYY')),
            str_starts_with($locale, 'fr') ? $start->format('H\\hi') : $start->format('H:i'),
        ];
    }

    private function eventVenue(EventDomainObject $event, mixed $settingsDetails): string
    {
        $details = $this->decode($event->getLocationDetails());
        $venue = trim((string) ($details['venue_name'] ?? $event->getLocation() ?? ''));
        $city = trim((string) ($details['city'] ?? ''));

        if ($venue === '' && $city === '') {
            $fallback = $this->decode($settingsDetails);
            $venue = trim((string) ($fallback['venue_name'] ?? ''));
            $city = trim((string) ($fallback['city'] ?? ''));
        }

        return implode(', ', array_filter([$venue, $city], fn (string $part) => $part !== ''));
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return is_array($value) ? $value : [];
    }

    /**
     * @param  Collection<int, ImageDomainObject>|null  $images
     */
    private function readImage(?Collection $images, ImageType $type): ?string
    {
        $image = $images?->first(fn (ImageDomainObject $image) => $image->getType() === $type->name);
        if ($image === null) {
            return null;
        }

        try {
            return $this->filesystemManager->disk($image->getDisk())->get($image->getPath());
        } catch (Throwable $exception) {
            $this->logger->warning('Could not read ticket image', [
                'image_id' => $image->getId(),
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function stripEmoji(string $text): string
    {
        $clean = preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}\x{200D}]/u', '', $text) ?? $text;

        return trim(preg_replace('/\s+/', ' ', $clean) ?? $clean);
    }
}
