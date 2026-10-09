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
use HiEvents\Helper\CustomerLocale;
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

    private const MAX_TICKETS_PER_EMAIL = 10;

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
                ->locale(CustomerLocale::get())
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
            ->locale(CustomerLocale::get())
            ->send($mail);
    }

    /**
     * @param  AttendeeDomainObject[]  $bundledTickets
     */
    /**
     * Every address receives all of its tickets as PDF attachments, at most MAX_TICKETS_PER_EMAIL per e-mail.
     *
     * @param  AttendeeDomainObject[]  $bundledTickets  Already attached to the buyer's order summary
     */
    private function sendAttendeeTicketEmails(OrderDomainObject $order, EventDomainObject $event, array $bundledTickets): void
    {
        $bundledIds = array_map(static fn (AttendeeDomainObject $attendee) => $attendee->getId(), $bundledTickets);

        $ticketsByEmail = [];
        $attendees = ($order->getAttendees() ?? collect())->sortBy(static fn (AttendeeDomainObject $attendee) => $attendee->getId());
        foreach ($attendees as $attendee) {
            if (KioskSentinelEmail::isKioskSentinelEmail($attendee->getEmail())
                || in_array($attendee->getId(), $bundledIds, true)) {
                continue;
            }

            $ticketsByEmail[mb_strtolower(trim((string) $attendee->getEmail()))][] = $attendee;
        }

        foreach ($ticketsByEmail as $tickets) {
            foreach (array_chunk($tickets, self::MAX_TICKETS_PER_EMAIL) as $chunk) {
                $this->sendAttendeeTicketService->send(
                    order: $order,
                    attendee: $chunk[0],
                    event: $event,
                    eventSettings: $event->getEventSettings(),
                    organizer: $event->getOrganizer(),
                    tickets: $chunk,
                );
            }
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
