<?php

namespace HiEvents\Services\Domain\Mail;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\Enums\MessageTypeEnum;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\DomainObjects\Status\MessageStatus;
use HiEvents\Exceptions\UnableToSendMessageException;
use HiEvents\Helper\KioskSentinelEmail;
use HiEvents\Helper\Url;
use HiEvents\Jobs\Event\SendEventEmailJob;
use HiEvents\Mail\Event\EventMessage;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\MessageRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\UserRepositoryInterface;
use HiEvents\Services\Application\Handlers\Message\DTO\SendMessageDTO;
use HiEvents\Services\Domain\Attendee\AttendanceUrlMessageVariableRenderer;
use HiEvents\Services\Domain\Attendee\AttendeeAttendanceLinkGenerator;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Log\Logger;

class SendEventEmailMessagesService
{
    private const ATTENDEE_COLUMNS = ['id', 'event_id', 'short_id', 'first_name', 'last_name', 'email'];

    private array $sentEmails = [];

    private bool $hasAttendanceVariable = false;

    private bool $attendanceLinksEnabled = false;

    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly AttendeeRepositoryInterface $attendeeRepository,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly MessageRepositoryInterface $messageRepository,
        private readonly UserRepositoryInterface $userRepository,
        private readonly Logger $logger,
        private readonly Dispatcher $dispatcher,
        private readonly AttendeeAttendanceLinkGenerator $attendanceLinkGenerator,
        private readonly AttendanceUrlMessageVariableRenderer $attendanceUrlRenderer,
    ) {}

    /**
     * @throws UnableToSendMessageException
     */
    public function send(SendMessageDTO $messageData): void
    {
        $event = $this->eventRepository
            ->loadRelation(EventSettingDomainObject::class)
            ->loadRelation(new Relationship(
                domainObject: OrganizerDomainObject::class,
                name: 'organizer'
            ))
            ->findById($messageData->event_id);

        $order = $this->orderRepository->findFirstWhere([
            'id' => $messageData->order_id,
            'event_id' => $messageData->event_id,
        ]);

        if ((! $order && $messageData->type === MessageTypeEnum::ORDER_OWNER) || ! $messageData->id) {
            $message = 'Unable to send message. Order or message ID not present.';
            $this->logger->error($message, $messageData->toArray());
            $this->updateMessageStatus($messageData, MessageStatus::FAILED);

            throw new UnableToSendMessageException($message);
        }

        $this->hasAttendanceVariable = $this->attendanceUrlRenderer->containsVariable($messageData->message);
        $this->attendanceLinksEnabled = $this->hasAttendanceVariable
            && (bool) $event->getEventSettings()?->getAllowAttendeeAttendanceResponse();

        switch ($messageData->type) {
            case MessageTypeEnum::INDIVIDUAL_ATTENDEES:
                $this->sendAttendeeMessages($messageData, $event);
                break;
            case MessageTypeEnum::ORDER_OWNER:
                $this->sendOrderMessages($messageData, $event, $order);
                break;
            case MessageTypeEnum::TICKET_HOLDERS:
                $this->sendTicketHolderMessages($messageData, $event);
                break;
            case MessageTypeEnum::ALL_ATTENDEES:
                $this->sendEventMessages($messageData, $event);
                break;
            case MessageTypeEnum::ORDER_OWNERS_WITH_PRODUCT:
                $this->sendProductMessages($messageData, $event);
                break;
        }

        $this->updateMessageStatus($messageData, MessageStatus::SENT);
    }

    private function sendAttendeeMessages(SendMessageDTO $messageData, EventDomainObject $event): void
    {
        $attendees = $this->attendeeRepository->findWhereIn(
            field: 'id',
            values: $messageData->attendee_ids,
            additionalWhere: [
                'event_id' => $messageData->event_id,
            ],
            columns: self::ATTENDEE_COLUMNS
        );

        $this->emailAttendees($attendees, $messageData, $event);
    }

    private function sendTicketHolderMessages(SendMessageDTO $messageData, EventDomainObject $event): void
    {
        $attendees = $this->attendeeRepository->findWhereIn(
            field: 'product_id',
            values: $messageData->product_ids,
            additionalWhere: [
                'event_id' => $messageData->event_id,
                'status' => AttendeeStatus::ACTIVE->name,
            ],
            columns: self::ATTENDEE_COLUMNS
        );

        $this->emailAttendees($attendees, $messageData, $event);
    }

    private function sendOrderMessages(
        SendMessageDTO $messageData,
        EventDomainObject $event,
        OrderDomainObject $order,
    ): void {
        $this->sendEmailToMessageSender($messageData, $event);

        if (KioskSentinelEmail::isKioskSentinelEmail($order->getEmail())) {
            return;
        }

        $this->sendMessage(
            emailAddress: $order->getEmail(),
            fullName: $order->getFullName(),
            messageData: $messageData,
            event: $event,
            messageHtml: $this->renderMessage($messageData, $this->getOrderSummaryUrl($order)),
        );
    }

    private function emailAttendees(
        Collection $attendees,
        SendMessageDTO $messageData,
        EventDomainObject $event,
    ): void {
        $this->sendEmailToMessageSender($messageData, $event);

        if ($messageData->is_test) {
            return;
        }

        $sentEmails = [];
        $attendees->each(function (AttendeeDomainObject $attendee) use (&$sentEmails, $event, $messageData) {
            if (KioskSentinelEmail::isKioskSentinelEmail($attendee->getEmail())) {
                return;
            }

            $dedupeKey = $this->attendanceLinksEnabled
                ? 'attendee-' . $attendee->getId()
                : $attendee->getEmail();

            if (in_array($dedupeKey, $sentEmails, true)) {
                return;
            }

            $sentEmails[] = $dedupeKey;

            $this->sendMessage(
                emailAddress: $attendee->getEmail(),
                fullName: $attendee->getFullName(),
                messageData: $messageData,
                event: $event,
                messageHtml: $this->renderMessage(
                    $messageData,
                    $this->attendanceLinksEnabled ? $this->attendanceLinkGenerator->getAttendanceUrl($attendee) : null,
                ),
                dedupeKey: $dedupeKey,
            );
        });
    }

    private function updateMessageStatus(SendMessageDTO $messageData, MessageStatus $status): void
    {
        $attributes = [
            'status' => $status->name,
        ];

        if ($status === MessageStatus::SENT) {
            $attributes['sent_at'] = now()->toDateTimeString();
        }

        $this->messageRepository->updateWhere(
            attributes: $attributes,
            where: [
                'id' => $messageData->id,
            ]
        );
    }

    /**
     * @todo - Load test this. Events can have a lot of attendees.
     */
    private function sendEventMessages(SendMessageDTO $messageData, EventDomainObject $event): void
    {
        $attendees = $this->attendeeRepository->findWhere(
            where: [
                'event_id' => $messageData->event_id,
                'status' => AttendeeStatus::ACTIVE->name,
            ],
            columns: self::ATTENDEE_COLUMNS
        );

        $this->emailAttendees($attendees, $messageData, $event);
    }

    private function sendEmailToMessageSender(SendMessageDTO $messageData, EventDomainObject $event): void
    {
        if (! $messageData->send_copy_to_current_user && ! $messageData->is_test) {
            return;
        }

        $user = $this->userRepository->findById($messageData->sent_by_user_id);

        $this->sendMessage(
            emailAddress: $user->getEmail(),
            fullName: $user->getFullName(),
            messageData: $messageData,
            event: $event,
            messageHtml: $this->renderMessage($messageData, null),
        );
    }

    private function sendProductMessages(SendMessageDTO $messageData, EventDomainObject $event): void
    {
        $orders = $this->orderRepository->findOrdersAssociatedWithProducts(
            eventId: $messageData->event_id,
            productIds: $messageData->product_ids,
            orderStatuses: $messageData->order_statuses
        );

        if ($orders->isEmpty()) {
            return;
        }

        $this->sendEmailToMessageSender($messageData, $event);

        $orders->each(function (OrderDomainObject $order) use ($messageData, $event) {
            if (KioskSentinelEmail::isKioskSentinelEmail($order->getEmail())) {
                return;
            }

            $this->sendMessage(
                emailAddress: $order->getEmail(),
                fullName: $order->getFullName(),
                messageData: $messageData,
                event: $event,
                messageHtml: $this->renderMessage($messageData, $this->getOrderSummaryUrl($order)),
            );
        });
    }

    private function sendMessage(
        string $emailAddress,
        string $fullName,
        SendMessageDTO $messageData,
        EventDomainObject $event,
        ?string $messageHtml = null,
        ?string $dedupeKey = null,
    ): void {
        $dedupeKey ??= $emailAddress;

        if (in_array($dedupeKey, $this->sentEmails, true)) {
            return;
        }

        $this->dispatcher->dispatch(
            new SendEventEmailJob(
                email: $emailAddress,
                toName: $fullName,
                eventMessage: new EventMessage(
                    event: $event,
                    eventSettings: $event->getEventSettings(),
                    messageData: $messageData,
                    messageHtml: $messageHtml,
                ),
                messageData: $messageData,
            )
        );

        $this->sentEmails[] = $dedupeKey;
    }

    private function renderMessage(SendMessageDTO $messageData, ?string $attendanceUrl): ?string
    {
        if (! $this->hasAttendanceVariable) {
            return null;
        }

        if (! $this->attendanceLinksEnabled) {
            return $this->attendanceUrlRenderer->strip($messageData->message);
        }

        return $this->attendanceUrlRenderer->render($messageData->message, $attendanceUrl);
    }

    private function getOrderSummaryUrl(OrderDomainObject $order): string
    {
        return sprintf(
            Url::getPublicFrontEndUrlForEventId(Url::ORDER_SUMMARY, $order->getEventId()),
            $order->getEventId(),
            $order->getShortId(),
        );
    }
}
