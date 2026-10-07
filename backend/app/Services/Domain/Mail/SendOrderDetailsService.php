<?php

namespace HiEvents\Services\Domain\Mail;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\ImageDomainObject;
use HiEvents\DomainObjects\InvoiceDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Helper\KioskSentinelEmail;
use HiEvents\Mail\Order\OrderFailed;
use HiEvents\Mail\Organizer\OrderSummaryForOrganizer;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Domain\Attendee\SendAttendeeTicketService;
use HiEvents\Services\Domain\Email\MailBuilderService;
use Illuminate\Mail\Mailer;

class SendOrderDetailsService
{
    private const MAX_TICKETS_IN_SUMMARY = 10;

    public function __construct(
        private readonly EventRepositoryInterface $eventRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly Mailer $mailer,
        private readonly SendAttendeeTicketService $sendAttendeeTicketService,
        private readonly MailBuilderService $mailBuilderService,
    ) {}

    public function sendOrderSummaryAndTicketEmails(OrderDomainObject $order): void
    {
        $order = $this->orderRepository
            ->loadRelation(OrderItemDomainObject::class)
            ->loadRelation(AttendeeDomainObject::class)
            ->loadRelation(InvoiceDomainObject::class)
            ->findById($order->getId());

        $event = $this->eventRepository
            ->loadRelation(new Relationship(
                OrganizerDomainObject::class,
                nested: [new Relationship(ImageDomainObject::class, name: 'images')],
                name: 'organizer'
            ))
            ->loadRelation(new Relationship(EventSettingDomainObject::class))
            ->findById($order->getEventId());

        if ($order->isOrderCompleted() || $order->isOrderAwaitingOfflinePayment()) {
            $bundledTickets = $this->ticketsBundledWithSummary($order);
            $this->sendOrderSummaryEmails($order, $event, $bundledTickets);
            $this->sendAttendeeTicketEmails($order, $event, $bundledTickets);
        }

        if ($order->isOrderFailed() && $order->getEmail()) {
            $this->mailer
                ->to($order->getEmail())
                ->locale($order->getLocale())
                ->send(new OrderFailed(
                    order: $order,
                    event: $event,
                    organizer: $event->getOrganizer(),
                    eventSettings: $event->getEventSettings(),
                ));
        }
    }

    public function sendCustomerOrderSummary(
        OrderDomainObject $order,
        EventDomainObject $event,
        OrganizerDomainObject $organizer,
        EventSettingDomainObject $eventSettings,
        ?InvoiceDomainObject $invoice = null,
        array $ticketAttendees = [],
    ): void {
        $mail = $this->mailBuilderService->buildOrderSummaryMail(
            $order,
            $event,
            $eventSettings,
            $organizer,
            $invoice,
            $ticketAttendees,
        );

        if (KioskSentinelEmail::isKioskSentinelEmail($order->getEmail())) {
            return;
        }

        $this->mailer
            ->to($order->getEmail())
            ->locale($order->getLocale())
            ->send($mail);
    }

    /**
     * @param  AttendeeDomainObject[]  $bundledTickets
     */
    private function sendAttendeeTicketEmails(OrderDomainObject $order, EventDomainObject $event, array $bundledTickets): void
    {
        $sentEmails = array_map(static fn (AttendeeDomainObject $attendee) => $attendee->getEmail(), $bundledTickets);
        foreach ($order->getAttendees() as $attendee) {
            if (KioskSentinelEmail::isKioskSentinelEmail($attendee->getEmail())) {
                continue;
            }

            if (in_array($attendee->getEmail(), $sentEmails, true)) {
                continue;
            }

            $this->sendAttendeeTicketService->send(
                order: $order,
                attendee: $attendee,
                event: $event,
                eventSettings: $event->getEventSettings(),
                organizer: $event->getOrganizer(),
            );

            $sentEmails[] = $attendee->getEmail();
        }
    }

    /**
     * @param  AttendeeDomainObject[]  $bundledTickets
     */
    private function sendOrderSummaryEmails(OrderDomainObject $order, EventDomainObject $event, array $bundledTickets): void
    {
        if (! $this->isFreeOrder($order)) {
            $this->sendCustomerOrderSummary(
                order: $order,
                event: $event,
                organizer: $event->getOrganizer(),
                eventSettings: $event->getEventSettings(),
                invoice: $order->getLatestInvoice(),
                ticketAttendees: $bundledTickets,
            );
        }

        if ($order->getIsManuallyCreated() || ! $event->getEventSettings()->getNotifyOrganizerOfNewOrders()) {
            return;
        }

        $this->mailer
            ->to($event->getOrganizer()->getEmail())
            ->send(new OrderSummaryForOrganizer($order, $event));
    }

    private function isFreeOrder(OrderDomainObject $order): bool
    {
        $ticketItems = $order->getTicketOrderItems();

        return $ticketItems->isNotEmpty()
            && $ticketItems->every(static fn ($item) => (float) $item->getPrice() <= 0);
    }

    /**
     * Tickets of attendees sharing the buyer's e-mail travel with the order summary, so the buyer
     * gets a single e-mail. Free orders already send one e-mail per attendee, and very large orders
     * keep separate e-mails to avoid a heavy message.
     *
     * @return AttendeeDomainObject[]
     */
    private function ticketsBundledWithSummary(OrderDomainObject $order): array
    {
        $buyerEmail = mb_strtolower(trim((string) $order->getEmail()));
        if ($buyerEmail === '' || $this->isFreeOrder($order) || KioskSentinelEmail::isKioskSentinelEmail($order->getEmail())) {
            return [];
        }

        $tickets = ($order->getAttendees() ?? collect())
            ->filter(static fn (AttendeeDomainObject $attendee) => mb_strtolower(trim((string) $attendee->getEmail())) === $buyerEmail)
            ->values()
            ->all();

        return count($tickets) <= self::MAX_TICKETS_IN_SUMMARY ? $tickets : [];
    }
}
