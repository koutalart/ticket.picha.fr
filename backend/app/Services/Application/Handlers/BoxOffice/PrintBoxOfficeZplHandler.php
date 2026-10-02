<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\BoxOffice;

use Carbon\Carbon;
use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Generated\AttendeeDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\PrintJobDomainObjectAbstract;
use HiEvents\DomainObjects\ImageDomainObject;
use HiEvents\Exceptions\InvalidZebraPrinterHostException;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Exceptions\ZebraPrinterUnreachableException;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\BoxOfficePrinterPreferenceRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Repository\Interfaces\PrintJobRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Services\Application\Handlers\BoxOffice\DTO\PrintBoxOfficeZplDTO;
use HiEvents\Services\Domain\Ticket\AttendeeTicketZplService;
use HiEvents\Services\Domain\Ticket\DTO\ZplTicketDataDTO;
use HiEvents\Services\Infrastructure\Printing\ZebraPrinterClientInterface;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;
use Throwable;

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
        private readonly BoxOfficePrinterPreferenceRepositoryInterface $printerPreferenceRepository,
        private readonly AttendeeTicketZplService $attendeeTicketZplService,
        private readonly ZebraPrinterClientInterface $zebraPrinterClient,
        private readonly FilesystemManager $filesystemManager,
        private readonly LoggerInterface $logger,
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

        $event = $this->eventRepository
            ->loadRelation(ImageDomainObject::class)
            ->findById($dto->event_id);
        $product = $attendee->getProduct()
            ?? $this->productRepository->findById($attendee->getProductId());

        $organizer = $event->getOrganizerId()
            ? $this->organizerRepository
                ->loadRelation(ImageDomainObject::class)
                ->findById($event->getOrganizerId())
            : null;

        $date = '';
        $time = '';
        if ($event->getStartDate()) {
            $start = Carbon::parse($event->getStartDate())
                ->timezone($event->getTimezone() ?: 'UTC')
                ->locale('fr');
            $date = $this->formatTicketDate($start);
            $time = $start->format('H\\hi');
        }

        $organizerLogo = $this->readImage($event->getImages(), ImageType::TICKET_LOGO)
            ?? $this->readImage($organizer?->getImages(), ImageType::ORGANIZER_LOGO);

        $previousLocale = app()->getLocale();
        app()->setLocale('fr');
        try {
            $zpl = $this->attendeeTicketZplService->generate(new ZplTicketDataDTO(
                publicId: $attendee->getPublicId(),
                eventTitle: $event->getTitle(),
                productTitle: $this->stripEmoji($product->getTitle()),
                attendeeName: trim($attendee->getFirstName().' '.$attendee->getLastName()),
                eventDate: $date,
                eventTime: $time,
                venue: $this->eventVenue($event),
                sellerName: $organizer?->getName() ?? '',
                organizerLogo: $organizerLogo,
                sponsorLogo: $this->readImage($event->getImages(), ImageType::TICKET_SPONSOR_LOGO),
            ));
        } finally {
            app()->setLocale($previousLocale);
        }

        $this->zebraPrinterClient->send($dto->printer_host, self::PRINTER_PORT, $zpl);

        $this->printJobRepository->create([
            PrintJobDomainObjectAbstract::ATTENDEE_ID => $attendee->getId(),
            PrintJobDomainObjectAbstract::AGENT_USER_ID => $dto->agent_user_id,
            PrintJobDomainObjectAbstract::PRINTED_AT => now()->toDateTimeString(),
        ]);

        $this->printerPreferenceRepository->rememberHost($dto->agent_user_id, $dto->event_id, $dto->printer_host);
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
            $this->logger->warning('Could not read ticket image for ZPL printing', [
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

    private function formatTicketDate(Carbon $start): string
    {
        return ucfirst($start->isoFormat('ddd D MMM YYYY'));
    }

    private function eventVenue(EventDomainObject $event): string
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
            $settings = $this->eventSettingsRepository->findFirstWhere(['event_id' => $event->getId()]);
            $settingsDetails = $settings?->getLocationDetails();
            if (is_string($settingsDetails)) {
                $settingsDetails = json_decode($settingsDetails, true) ?: [];
            }
            if (is_array($settingsDetails)) {
                $venue = trim((string) ($settingsDetails['venue_name'] ?? ''));
                $city = trim((string) ($settingsDetails['city'] ?? ''));
            }
        }

        return implode(', ', array_filter([$venue, $city], fn (string $part) => $part !== ''));
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
