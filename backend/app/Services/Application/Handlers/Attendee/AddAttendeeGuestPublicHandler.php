<?php

namespace HiEvents\Services\Application\Handlers\Attendee;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\AttendanceResponseNotAllowedException;
use HiEvents\Exceptions\AttendeeGuestNotAllowedException;
use HiEvents\Exceptions\InvalidAttendanceCodeException;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Application\Handlers\Attendee\DTO\AddAttendeeGuestPublicDTO;
use HiEvents\Services\Application\Handlers\Attendee\DTO\AttendeeAttendanceStatusDTO;
use HiEvents\Services\Domain\Attendee\AttendeeAttendanceService;
use HiEvents\Services\Domain\Attendee\AttendeeGuestService;
use HiEvents\Services\Domain\Attendee\DTO\AttendeeAttendanceContextDTO;
use HiEvents\Services\Domain\Attendee\DTO\AttendeeGuestDataDTO;
use HiEvents\Services\Domain\Attendee\SendAttendeeTicketService;
use HiEvents\Services\Infrastructure\DomainEvents\DomainEventDispatcherService;
use HiEvents\Services\Infrastructure\DomainEvents\Enums\DomainEventType;
use HiEvents\Services\Infrastructure\DomainEvents\Events\AttendeeEvent;
use HiEvents\Services\Infrastructure\HtmlPurifier\HtmlPurifierService;
use Illuminate\Database\DatabaseManager;
use Psr\Log\LoggerInterface;
use Throwable;

class AddAttendeeGuestPublicHandler
{
    public function __construct(
        private readonly AttendeeAttendanceService    $attendeeAttendanceService,
        private readonly AttendeeGuestService         $attendeeGuestService,
        private readonly SendAttendeeTicketService    $sendAttendeeTicketService,
        private readonly EventRepositoryInterface     $eventRepository,
        private readonly OrderRepositoryInterface     $orderRepository,
        private readonly DomainEventDispatcherService $domainEventDispatcherService,
        private readonly HtmlPurifierService          $purifier,
        private readonly DatabaseManager              $databaseManager,
        private readonly LoggerInterface              $logger,
    )
    {
    }

    /**
     * @throws InvalidAttendanceCodeException
     * @throws AttendanceResponseNotAllowedException
     * @throws AttendeeGuestNotAllowedException
     * @throws Throwable
     */
    public function handle(AddAttendeeGuestPublicDTO $data): AttendeeAttendanceStatusDTO
    {
        $context = $this->attendeeAttendanceService->getContext(
            eventId: $data->eventId,
            attendeeShortId: $data->attendeeShortId,
            code: $data->code,
        );

        $guestData = new AttendeeGuestDataDTO(
            firstName: $this->sanitize($data->firstName),
            lastName: $this->sanitize($data->lastName),
            phone: $data->phone !== null ? $this->sanitize($data->phone) : null,
        );

        if ($guestData->firstName === '' || $guestData->lastName === '') {
            throw new AttendeeGuestNotAllowedException(
                __('Please enter the first and last name of your guest.', [], $context->locale)
            );
        }

        $guest = $this->databaseManager->transaction(
            fn() => $this->attendeeGuestService->addGuest($context, $guestData)
        );

        $this->domainEventDispatcherService->dispatch(
            new AttendeeEvent(
                type: DomainEventType::ATTENDEE_CREATED,
                attendeeId: $guest->getId(),
            )
        );

        $this->sendTicket($context, $guest);

        return new AttendeeAttendanceStatusDTO(
            attendee: $context->attendee,
            blockingReason: $this->attendeeAttendanceService->getBlockingReason($context),
            guestAvailability: $this->attendeeGuestService->getAvailability($context),
        );
    }

    private function sendTicket(AttendeeAttendanceContextDTO $context, AttendeeDomainObject $guest): void
    {
        try {
            $event = $this->eventRepository
                ->loadRelation(new Relationship(OrganizerDomainObject::class, name: 'organizer'))
                ->loadRelation(EventSettingDomainObject::class)
                ->findById($context->event->getId());

            $order = $this->orderRepository
                ->loadRelation(OrderItemDomainObject::class)
                ->findById($guest->getOrderId());

            $this->sendAttendeeTicketService->send(
                order: $order,
                attendee: $guest,
                event: $event,
                eventSettings: $event->getEventSettings(),
                organizer: $event->getOrganizer(),
            );
        } catch (Throwable $exception) {
            $this->logger->error('Failed to send the ticket of a guest added by an attendee', [
                'attendee_id' => $guest->getId(),
                'event_id' => $context->event->getId(),
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    private function sanitize(string $value): string
    {
        $purified = (string)$this->purifier->purify($value);

        return trim(strip_tags(html_entity_decode($purified, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }
}
