<?php

declare(strict_types=1);

namespace Tests\Unit\Mail;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Helper\IdHelper;
use HiEvents\Models\Attendee;
use HiEvents\Models\Event;
use HiEvents\Models\EventSetting;
use HiEvents\Models\Order;
use HiEvents\Models\OrderItem;
use HiEvents\Models\Organizer;
use HiEvents\Services\Domain\Event\EventCalendarFileService;
use HiEvents\Services\Domain\Mail\SendOrderDetailsService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Symfony\Component\Mime\Email;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

class OrderEmailsBundlingTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    private function paidOrder(float $price, array $attendeeEmails): Order
    {
        [$event, $product, $productPrice] = $this->createEventWithProduct(price: $price);
        Event::whereKey($event->id)->update(['start_date' => '2026-10-11 15:00:00', 'timezone' => 'Indian/Mayotte', 'currency' => 'EUR']);
        EventSetting::where('event_id', $event->id)->update(['notify_organizer_of_new_orders' => false]);

        $order = Order::create([
            'event_id' => $event->id,
            'total_gross' => $price * count($attendeeEmails),
            'currency' => 'EUR',
            'status' => 'COMPLETED',
            'payment_status' => $price > 0 ? 'PAYMENT_RECEIVED' : 'NO_PAYMENT_REQUIRED',
            'first_name' => 'Client',
            'last_name' => 'Exemple',
            'email' => 'client@exemple.test',
            'locale' => 'fr',
            'short_id' => IdHelper::shortId(IdHelper::ORDER_PREFIX),
            'public_id' => IdHelper::publicId(IdHelper::ORDER_PREFIX),
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_price_id' => $productPrice->id,
            'quantity' => count($attendeeEmails),
            'price' => $price,
            'total_before_additions' => $price * count($attendeeEmails),
            'item_name' => 'Entrée simple',
            'product_type' => 'TICKET',
        ]);

        foreach ($attendeeEmails as $index => $email) {
            Attendee::create([
                'event_id' => $event->id,
                'order_id' => $order->id,
                'product_id' => $product->id,
                'product_price_id' => $productPrice->id,
                'status' => 'ACTIVE',
                'email' => $email,
                'first_name' => 'Participant',
                'last_name' => (string) ($index + 1),
                'short_id' => IdHelper::shortId(IdHelper::ATTENDEE_PREFIX),
                'public_id' => IdHelper::publicId(IdHelper::ATTENDEE_PREFIX),
            ]);
        }

        return $order;
    }

    /**
     * @return list<array{to: string, subject: string, attachments: list<string>}>
     */
    private function send(Order $order): array
    {
        config(['mail.default' => 'array']);
        app()->forgetInstance('mailer');
        app()->forgetInstance('mail.manager');

        app(SendOrderDetailsService::class)->sendOrderSummaryAndTicketEmails(OrderDomainObject::hydrateFromModel($order));

        return app('mailer')->getSymfonyTransport()->messages()->map(function ($sent) {
            /** @var Email $email */
            $email = $sent->getOriginalMessage();

            return [
                'to' => $email->getTo()[0]->getAddress(),
                'subject' => (string) $email->getSubject(),
                'attachments' => array_map(fn ($part) => (string) $part->getFilename(), $email->getAttachments()),
            ];
        })->values()->all();
    }

    private function tickets(array $message): array
    {
        return array_values(array_filter($message['attachments'], fn ($name) => str_starts_with($name, 'ticket-')));
    }

    public function test_buyer_gets_a_single_email_with_their_ticket_attached(): void
    {
        $messages = $this->send($this->paidOrder(40, ['client@exemple.test']));

        self::assertCount(1, $messages);
        self::assertSame('client@exemple.test', $messages[0]['to']);
        self::assertStringContainsString('commande', mb_strtolower($messages[0]['subject']));
        self::assertContains('event.ics', $messages[0]['attachments']);
        self::assertCount(1, $this->tickets($messages[0]));
    }

    public function test_guest_with_another_email_still_gets_their_own_ticket(): void
    {
        $messages = $this->send($this->paidOrder(40, ['client@exemple.test', 'invite@exemple.test']));

        self::assertCount(2, $messages);
        $byRecipient = array_column($messages, null, 'to');
        self::assertCount(1, $this->tickets($byRecipient['client@exemple.test']));
        self::assertMatchesRegularExpression('/billet|ticket/', mb_strtolower($byRecipient['invite@exemple.test']['subject']));
        self::assertCount(1, $this->tickets($byRecipient['invite@exemple.test']));
    }

    public function test_free_order_keeps_one_ticket_email_and_no_summary(): void
    {
        $messages = $this->send($this->paidOrder(0, ['client@exemple.test']));

        self::assertCount(1, $messages);
        self::assertMatchesRegularExpression('/billet|ticket/', mb_strtolower($messages[0]['subject']));
    }

    public function test_large_orders_keep_separate_emails(): void
    {
        $messages = $this->send($this->paidOrder(40, array_fill(0, 11, 'client@exemple.test')));

        $summary = array_values(array_filter($messages, fn ($message) => str_contains(mb_strtolower($message['subject']), 'commande')));
        self::assertCount(1, $summary);
        self::assertCount(0, $this->tickets($summary[0]));

        $ticketEmails = array_values(array_filter($messages, fn ($message) => ! str_contains(mb_strtolower($message['subject']), 'commande')));
        self::assertCount(2, $ticketEmails, '11 tickets: one e-mail of 10 and one of 1');
        self::assertSame(11, array_sum(array_map(fn ($message) => count($this->tickets($message)), $ticketEmails)));
    }

    public function test_free_order_sends_every_ticket_of_the_same_address(): void
    {
        $messages = $this->send($this->paidOrder(0, ['client@exemple.test', 'client@exemple.test', 'client@exemple.test']));

        self::assertCount(1, $messages);
        self::assertCount(3, $this->tickets($messages[0]));
        self::assertContains('event.ics', $messages[0]['attachments']);
    }

    public function test_free_order_sends_each_address_its_own_tickets(): void
    {
        $messages = $this->send($this->paidOrder(0, ['client@exemple.test', 'Client@Exemple.test', 'invite@exemple.test']));
        $byRecipient = array_column($messages, null, 'to');

        self::assertCount(2, $messages);
        self::assertCount(2, $this->tickets($byRecipient['client@exemple.test']));
        self::assertCount(1, $this->tickets($byRecipient['invite@exemple.test']));
    }

    public function test_calendar_file_uses_the_event_local_time(): void
    {
        $order = $this->paidOrder(40, ['client@exemple.test']);
        $event = EventDomainObject::hydrateFromModel(Event::findOrFail($order->event_id));

        $ics = app(EventCalendarFileService::class)->ics(
            $event,
            OrganizerDomainObject::hydrateFromModel(Organizer::findOrFail($event->getOrganizerId())),
            EventSettingDomainObject::hydrateFromModel(EventSetting::where('event_id', $event->getId())->firstOrFail()),
            'test',
        );

        self::assertMatchesRegularExpression('/DTSTART(;TZID=Indian\/Mayotte)?:20261011T180000|DTSTART:20261011T150000Z/', $ics);
    }
}
