<?php

namespace HiEvents\Services\Domain\Mail;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\InvoiceDomainObject;
use HiEvents\DomainObjects\ImageDomainObject;
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
            $this->sendOrderSummaryEmails($order, $event);
            $this->sendAttendeeTicketEmails($order, $event);
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
        ?InvoiceDomainObject $invoice = null
    ): void {
        $mail = $this->mailBuilderService->buildOrderSummaryMail(
            $order,
            $event,
            $eventSettings,
            $organizer,
            $invoice
        );

        if (KioskSentinelEmail::isKioskSentinelEmail($order->getEmail())) {
            return;
        }

        $this->mailer
            ->to($order->getEmail())
            ->locale($order->getLocale())
            ->send($mail);
    }

    private function sendAttendeeTicketEmails(OrderDomainObject $order, EventDomainObject $event): void
    {
        $sentEmails = [];
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

    private function sendOrderSummaryEmails(OrderDomainObject $order, EventDomainObject $event): void
    {
        if (!$this->isFreeOrder($order)) {
            $this->sendCustomerOrderSummary(
                order: $order,
                event: $event,
                organizer: $event->getOrganizer(),
                eventSettings: $event->getEventSettings(),
                invoice: $order->getLatestInvoice(),
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
}
