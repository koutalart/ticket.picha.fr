<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\BoxOffice;

use HiEvents\DomainObjects\Generated\AttendeeDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\PrintJobDomainObjectAbstract;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Repository\Interfaces\PrintJobRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Services\Domain\BoxOffice\DTO\BoxOfficeTicketZplDTO;
use HiEvents\Services\Domain\Ticket\AttendeeTicketZplService;
use HiEvents\Services\Domain\Ticket\DTO\ZplLabelFormatDTO;
use HiEvents\Services\Domain\Ticket\TicketContentService;

class BoxOfficeTicketZplService
{
    public function __construct(
        private readonly AttendeeRepositoryInterface $attendeeRepository,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly EventSettingsRepositoryInterface $eventSettingsRepository,
        private readonly OrganizerRepositoryInterface $organizerRepository,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly PrintJobRepositoryInterface $printJobRepository,
        private readonly AttendeeTicketZplService $attendeeTicketZplService,
        private readonly TicketContentService $ticketContentService,
    ) {}

    /**
     * @throws ResourceNotFoundException
     */
    public function build(int $eventId, string $attendeePublicId, ?ZplLabelFormatDTO $labelFormat = null): BoxOfficeTicketZplDTO
    {
        $attendee = $this->attendeeRepository->findFirstWhere([
            AttendeeDomainObjectAbstract::PUBLIC_ID => $attendeePublicId,
            AttendeeDomainObjectAbstract::EVENT_ID => $eventId,
        ]);

        if ($attendee === null) {
            throw new ResourceNotFoundException(__('Attendee not found.'));
        }

        $event = $this->eventRepository->findById($eventId);
        $product = $attendee->getProduct()
            ?? $this->productRepository->findById($attendee->getProductId());
        $eventSettings = $this->eventSettingsRepository->findFirstWhere(['event_id' => $event->getId()]);
        $organizer = $event->getOrganizerId()
            ? ($event->getOrganizer() ?? $this->organizerRepository->findById($event->getOrganizerId()))
            : null;

        $previousLocale = app()->getLocale();
        app()->setLocale('fr');
        try {
            $content = $this->ticketContentService->build($attendee, $event, $eventSettings, $organizer, $product);
            $zpl = $this->attendeeTicketZplService->generate(
                publicId: $content->public_id,
                eventTitle: $content->event_title,
                productTitle: $content->product_title,
                attendeeName: $content->attendee_name,
                eventWhen: $content->event_when,
                sponsorName: $content->sponsor_name,
                organizerName: $content->organizer_name,
                eventHours: $content->event_hours,
                venueName: $content->venue_name,
                venueCity: $content->venue_city,
                labelFormat: $labelFormat,
                eventLogoImage: $content->event_logo_image,
                sponsorLogoImage: $content->sponsor_logo_image,
            );
        } finally {
            app()->setLocale($previousLocale);
        }

        return new BoxOfficeTicketZplDTO(attendee_id: $attendee->getId(), zpl: $zpl);
    }

    public function recordPrintJob(int $attendeeId, int $agentUserId): void
    {
        $this->printJobRepository->create([
            PrintJobDomainObjectAbstract::ATTENDEE_ID => $attendeeId,
            PrintJobDomainObjectAbstract::AGENT_USER_ID => $agentUserId,
            PrintJobDomainObjectAbstract::PRINTED_AT => now()->toDateTimeString(),
        ]);
    }
}
