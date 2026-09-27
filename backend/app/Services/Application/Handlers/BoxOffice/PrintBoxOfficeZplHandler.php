<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\BoxOffice;

use Carbon\Carbon;
use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\Generated\AttendeeDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\ImageDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\PrintJobDomainObjectAbstract;
use HiEvents\Exceptions\InvalidZebraPrinterHostException;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Exceptions\ZebraPrinterUnreachableException;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Repository\Interfaces\ImageRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Repository\Interfaces\PrintJobRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Services\Application\Handlers\BoxOffice\DTO\PrintBoxOfficeZplDTO;
use HiEvents\Services\Domain\Ticket\AttendeeTicketZplService;
use HiEvents\Services\Infrastructure\Printing\ZebraPrinterClientInterface;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Str;

class PrintBoxOfficeZplHandler
{
    private const PRINTER_PORT = 9100;

    public function __construct(
        private readonly AttendeeRepositoryInterface $attendeeRepository,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly EventSettingsRepositoryInterface $eventSettingsRepository,
        private readonly OrganizerRepositoryInterface $organizerRepository,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly PrintJobRepositoryInterface $printJobRepository,
        private readonly AttendeeTicketZplService $attendeeTicketZplService,
        private readonly ZebraPrinterClientInterface $zebraPrinterClient,
        private readonly ImageRepositoryInterface $imageRepository,
        private readonly FilesystemManager $filesystemManager,
    ) {}

    /**
     * @throws InvalidZebraPrinterHostException
     * @throws ResourceNotFoundException
     * @throws ZebraPrinterUnreachableException
     */
    public function handle(PrintBoxOfficeZplDTO $dto): void
    {
        $this->assertPrivateLanHost($dto->printer_host);

        $attendee = $this->attendeeRepository->findFirstWhere([
            AttendeeDomainObjectAbstract::PUBLIC_ID => $dto->attendee_public_id,
            AttendeeDomainObjectAbstract::EVENT_ID => $dto->event_id,
        ]);

        if ($attendee === null) {
            throw new ResourceNotFoundException(__('Attendee not found.'));
        }

        $event = $this->eventRepository->findById($dto->event_id);
        $product = $attendee->getProduct()
            ?? $this->productRepository->findById($attendee->getProductId());

        $name = trim($attendee->getFirstName().' '.$attendee->getLastName());
        $when = '';
        $hours = '';
        $timezone = $event->getTimezone() ?: 'UTC';
        if ($event->getStartDate()) {
            $start = Carbon::parse($event->getStartDate())->timezone($timezone)->locale('fr');
            $when = $this->formatTicketDate($start);
            $end = $event->getEndDate()
                ? Carbon::parse($event->getEndDate())->timezone($timezone)
                : null;
            $hours = $this->formatTicketHours($start, $end);
        }

        $eventSettings = $this->eventSettingsRepository->findFirstWhere(['event_id' => $event->getId()]);
        [$venueName, $venueCity] = $this->eventVenue($event, $eventSettings);

        $organizerName = '';
        if ($event->getOrganizerId()) {
            $organizer = $event->getOrganizer()
                ?? $this->organizerRepository->findById($event->getOrganizerId());
            $organizerName = $organizer?->getName() ?? '';
        }

        $previousLocale = app()->getLocale();
        app()->setLocale('fr');
        try {
            $zpl = $this->attendeeTicketZplService->generate(
                publicId: $attendee->getPublicId(),
                eventTitle: $event->getTitle(),
                productTitle: $product->getTitle(),
                attendeeName: $name,
                eventWhen: $when,
                sponsorName: (string) $eventSettings?->getTicketSponsorName(),
                organizerName: $organizerName,
                eventHours: $hours,
                venueName: $venueName,
                venueCity: $venueCity,
                labelFormat: $dto->label_format,
                eventLogoImage: $this->eventImage($event->getId(), ImageType::TICKET_LOGO),
            );
        } finally {
            app()->setLocale($previousLocale);
        }

        $this->zebraPrinterClient->send($dto->printer_host, self::PRINTER_PORT, $zpl);

        $this->printJobRepository->create([
            PrintJobDomainObjectAbstract::ATTENDEE_ID => $attendee->getId(),
            PrintJobDomainObjectAbstract::AGENT_USER_ID => $dto->agent_user_id,
            PrintJobDomainObjectAbstract::PRINTED_AT => now()->toDateTimeString(),
        ]);
    }

    private function eventImage(int $eventId, ImageType $type): ?string
    {
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

    private function formatTicketDate(Carbon $start): string
    {
        return Str::ucfirst($start->isoFormat('ddd D MMM YYYY'));
    }

    private function formatTicketHours(Carbon $start, ?Carbon $end): string
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
    private function eventVenue(EventDomainObject $event, ?EventSettingDomainObject $settings): array
    {
        $details = $event->getLocationDetails();
        if (is_string($details)) {
            $details = json_decode($details, true) ?: [];
        }
        if (! is_array($details)) {
            $details = [];
        }

        $venue = trim((string) ($details['venue_name'] ?? $event->getLocation() ?? ''));
        $city = trim((string) ($details['city'] ?? ''));

        if ($venue === '' && $city === '') {
            $settingsDetails = $settings?->getLocationDetails();
            if (is_string($settingsDetails)) {
                $settingsDetails = json_decode($settingsDetails, true) ?: [];
            }
            if (is_array($settingsDetails)) {
                $venue = trim((string) ($settingsDetails['venue_name'] ?? ''));
                $city = trim((string) ($settingsDetails['city'] ?? ''));
            }
        }

        return [$venue, $city];
    }

    /**
     * @throws InvalidZebraPrinterHostException
     */
    private function assertPrivateLanHost(string $host): void
    {
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new InvalidZebraPrinterHostException(
                __('The printer address must be a private IPv4 address on the local network.')
            );
        }

        if (! preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[0-1])\.)/', $host)) {
            throw new InvalidZebraPrinterHostException(
                __('The printer address must be a private IPv4 address on the local network.')
            );
        }
    }
}
