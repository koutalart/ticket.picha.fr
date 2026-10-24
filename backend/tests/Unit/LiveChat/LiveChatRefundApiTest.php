<?php

declare(strict_types=1);

namespace Tests\Unit\LiveChat;

use HiEvents\Jobs\Sms\SendSmsInboundAlertJob;
use HiEvents\Mail\LiveChat\LiveChatContactAlertMail;
use HiEvents\Mail\LiveChat\LiveChatRefundAlertMail;
use HiEvents\Mail\LiveChat\LiveChatRefundCodeMail;
use HiEvents\Mail\Order\OrderRefunded;
use HiEvents\Models\Event;
use HiEvents\Models\Product;
use HiEvents\Models\ProductPrice;
use HiEvents\Services\Domain\Payment\Stripe\StripePaymentIntentRefundService;
use HiEvents\Services\Infrastructure\AI\ClaudeStructuredOutputClient;
use HiEvents\Services\Infrastructure\AI\DTO\ClaudeStructuredAnswerDTO;
use HiEvents\Services\Infrastructure\Stripe\StripeClientFactory;
use HiEvents\Values\MoneyValue;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Stripe\Refund;
use Stripe\StripeClient;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

class LiveChatRefundApiTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    private const BUYER_EMAIL = 'Awa.Said@Example.com';

    private const PASSWORD = 'Password-refund-1';

    private mixed $user;

    private const NEUTRAL = 'Je ne peux pas traiter cette demande automatiquement';

    private Event $event;

    private Product $ticket;

    private ProductPrice $ticketPrice;

    private Product $option;

    private ProductPrice $optionPrice;

    private int $organizerId;

    private ClaudeStructuredOutputClient|MockInterface $claude;

    private StripePaymentIntentRefundService|MockInterface $stripeRefunds;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->event, $this->ticket, $this->ticketPrice, $this->user] = $this->createEventWithProduct(price: 15.00, userPassword: self::PASSWORD);
        $this->organizerId = (int) $this->event->organizer_id;
        DB::table('events')->where('id', $this->event->id)->update([
            'currency' => 'EUR',
            'timezone' => 'Indian/Mayotte',
            'start_date' => now()->addDays(10),
            'end_date' => now()->addDays(10)->addHours(4),
        ]);
        DB::table('products')->where('id', $this->ticket->id)->update(['title' => 'Plein tarif']);
        DB::table('event_statistics')->insert(['event_id' => $this->event->id, 'created_at' => now(), 'updated_at' => now()]);

        $this->option = Product::create([
            'event_id' => $this->event->id,
            'title' => 'Option sérénité / billet',
            'product_type' => 'GENERAL',
            'type' => 'PAID',
            'order' => 2,
            'is_required' => true,
            'required_quantity_mode' => 'PER_TICKET',
            'is_refund_protection' => true,
        ]);
        $this->optionPrice = ProductPrice::create([
            'product_id' => $this->option->id,
            'price' => 0.49,
            'initial_quantity_available' => null,
            'quantity_sold' => 0,
            'order' => 1,
        ]);

        config([
            'services.anthropic.api_key' => 'test-key',
            'services.live_chat.model' => 'claude-haiku-5-5',
            'services.sms_watchdog.alert_email' => 'veille@picha.test',
            'app.saas_mode_enabled' => true,
        ]);

        $this->claude = Mockery::mock(ClaudeStructuredOutputClient::class);
        $this->claude->shouldReceive('isConfigured')->andReturn(true)->byDefault();
        $this->app->instance(ClaudeStructuredOutputClient::class, $this->claude);

        $this->stripeRefunds = Mockery::mock(StripePaymentIntentRefundService::class);
        $this->app->instance(StripePaymentIntentRefundService::class, $this->stripeRefunds);
        $factory = Mockery::mock(StripeClientFactory::class);
        $factory->shouldReceive('createForPlatform')->andReturn(new StripeClient('sk_test_dummy'));
        $this->app->instance(StripeClientFactory::class, $factory);

        Bus::fake([SendSmsInboundAlertJob::class]);
        Mail::fake();
    }

    public function test_refund_request_shows_the_refund_card(): void
    {
        $sessionId = $this->openChat();
        $this->answer('Je regarde ça avec toi : indique ton numéro de commande et ton e-mail.', intent: 'refund_request', withFollowUp: true);

        $this->chat('post', '/public/live-chat/messages', $sessionId, ['message' => 'Je veux être remboursé, je ne peux plus venir'])
            ->assertOk()
            ->assertJsonPath('data.refund_form', true)
            ->assertJsonPath('data.ask_contact', false);

        $session = DB::table('live_chat_sessions')->where('public_id', $sessionId)->first();
        $this->assertFalse((bool) $session->needs_follow_up);
    }

    public function test_refund_intent_is_ignored_without_any_protection_product(): void
    {
        DB::table('products')->where('id', $this->option->id)->update(['is_refund_protection' => false]);
        $sessionId = $this->openChat();
        $this->answer("L'organisateur va regarder ta demande.", intent: 'refund_request');

        $this->chat('post', '/public/live-chat/messages', $sessionId, ['message' => 'Je veux un remboursement'])
            ->assertOk()
            ->assertJsonPath('data.refund_form', false)
            ->assertJsonPath('data.ask_contact', true);

        $this->assertTrue((bool) DB::table('live_chat_sessions')->where('public_id', $sessionId)->value('needs_follow_up'));
    }

    public function test_full_refund_refunds_the_ticket_price_only_and_ignores_any_amount_sent(): void
    {
        config(['app.customer_locale' => 'fr']);
        DB::table('organizers')->where('id', $this->organizerId)->update(['email' => 'orga@example.com']);
        [$orderId, $attendeeIds] = $this->order();
        $sessionId = $this->openChat();

        $this->requestCode($sessionId, 'o-'.strtolower(substr($this->publicId($orderId), 2)), strtolower(self::BUYER_EMAIL))
            ->assertOk()
            ->assertJsonPath('data.step', 'code')
            ->assertJsonPath('data.attempts_left', 5);

        $code = $this->sentCode();
        $row = DB::table('live_chat_refund_requests')->where('order_id', $orderId)->first();
        $this->assertSame('CODE_SENT', $row->status);
        $this->assertNotSame($code, $row->code_hash);
        $this->assertTrue(Hash::check($code, $row->code_hash));

        $this->chat('post', '/public/live-chat/refund/verify', $sessionId, ['code' => $code === '000000' ? '111111' : '000000'])
            ->assertOk()
            ->assertJsonPath('data.step', 'code')
            ->assertJsonPath('data.attempts_left', 4);

        $this->chat('post', '/public/live-chat/refund/verify', $sessionId, ['code' => $code])
            ->assertOk()
            ->assertJsonPath('data.step', 'tickets')
            ->assertJsonPath('data.max_tickets', 1)
            ->assertJsonPath('data.currency', 'EUR')
            ->assertJsonPath('data.tickets.0.attendee_id', $attendeeIds[0])
            ->assertJsonPath('data.tickets.0.name', 'Awa Saïd')
            ->assertJsonPath('data.tickets.0.category', 'Plein tarif')
            ->assertJsonPath('data.tickets.0.amount_minor', 1500);

        $this->stripeRefunds->shouldReceive('refundPayment')
            ->once()
            ->withArgs(function (MoneyValue $amount, $payment, $client, array $metadata) use ($attendeeIds) {
                return $amount->toMinorUnit() === 1500
                    && $metadata[StripePaymentIntentRefundService::REFUND_SOURCE_KEY] === 'live_chat'
                    && $metadata['picha_attendee_ids'] === (string) $attendeeIds[0];
            })
            ->andReturn(Refund::constructFrom(['id' => 're_test']));

        $this->chat('post', '/public/live-chat/refund/confirm', $sessionId, [
            'attendee_ids' => [$attendeeIds[0]],
            'amount' => 999,
        ])
            ->assertOk()
            ->assertJsonPath('data.step', 'refunded')
            ->assertJsonPath('data.amount', 15);

        $this->assertSame('CANCELLED', DB::table('attendees')->where('id', $attendeeIds[0])->value('status'));
        $this->assertSame('REFUND_PENDING', DB::table('orders')->where('id', $orderId)->value('refund_status'));

        $row = DB::table('live_chat_refund_requests')->where('order_id', $orderId)->where('status', 'REFUNDED')->first();
        $this->assertSame('15.00', $row->amount);
        $this->assertSame([$attendeeIds[0]], json_decode($row->attendee_ids, true));
        $this->assertNotNull($row->refunded_at);
        $this->assertNull($row->code_hash);

        $session = DB::table('live_chat_sessions')->where('public_id', $sessionId)->first();
        $this->assertNotNull($session->refunded_at);
        $this->assertSame(strtolower(self::BUYER_EMAIL), $session->visitor_email);
        $last = DB::table('live_chat_messages')->where('live_chat_session_id', $session->id)->orderByDesc('id')->value('content');
        $this->assertStringContainsString('15,00 €', $last);
        $this->assertStringContainsString('5 à 10 jours', $last);
        $this->assertStringContainsString('vous seront remboursés', $last);

        Mail::assertQueued(OrderRefunded::class, fn (OrderRefunded $mail) => $mail->hasTo(self::BUYER_EMAIL));
        Mail::assertQueued(LiveChatRefundAlertMail::class, fn (LiveChatRefundAlertMail $mail) => $mail->hasTo('orga@example.com'));
        Mail::assertQueued(LiveChatRefundAlertMail::class, fn (LiveChatRefundAlertMail $mail) => $mail->hasTo('veille@picha.test'));

        $this->withHeader('Authorization', 'Bearer '.$this->loginAndGetToken($this->user, self::PASSWORD))
            ->getJson("/organizers/{$this->organizerId}/live-chats")
            ->assertOk()
            ->assertJsonPath('data.0.refunded_at', fn ($value) => $value !== null);
    }

    public function test_code_is_only_sent_to_the_email_of_the_order(): void
    {
        [$orderId] = $this->order();
        $sessionId = $this->openChat();

        $this->requestCode($sessionId, $this->publicId($orderId), strtoupper(self::BUYER_EMAIL))->assertJsonPath('data.step', 'code');

        Mail::assertQueued(LiveChatRefundCodeMail::class, 1);
        Mail::assertQueued(LiveChatRefundCodeMail::class, fn (LiveChatRefundCodeMail $mail) => $mail->hasTo(self::BUYER_EMAIL)
            && count($mail->to) === 1);
    }

    public function test_chat_steps_follow_the_tutoiement_of_the_organizer(): void
    {
        config(['app.customer_locale' => 'fr']);
        DB::table('organizer_settings')->updateOrInsert(['organizer_id' => $this->organizerId], ['customer_address_form' => 'tu']);
        [$orderId] = $this->order();
        $sessionId = $this->openChat();

        $this->requestCode($sessionId, $this->publicId($orderId), self::BUYER_EMAIL)
            ->assertJsonPath('data.messages.1.content', "Je t'ai envoyé un code à 6 chiffres à l'adresse e-mail de la commande. Saisis-le ci-dessous pour continuer, il est valable 15 minutes.");
    }

    public function test_order_can_be_found_by_its_short_id(): void
    {
        [$orderId] = $this->order();
        $sessionId = $this->openChat();
        $shortId = DB::table('orders')->where('id', $orderId)->value('short_id');

        $this->requestCode($sessionId, $shortId, self::BUYER_EMAIL)->assertJsonPath('data.step', 'code');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusals')]
    public function test_each_refusal_gets_the_same_neutral_answer(string $refusal, string $expectedReason): void
    {
        [$orderId, $attendeeIds] = $this->order();
        $email = self::BUYER_EMAIL;
        $reference = $this->publicId($orderId);

        match ($refusal) {
            'wrong_email' => $email = 'someone@example.com',
            'unknown_order' => $reference = 'O-NOTHING',
            'other_organizer' => $this->moveOrderToAnotherOrganizer($orderId),
            'not_completed' => DB::table('orders')->where('id', $orderId)->update(['status' => 'RESERVED']),
            'offline_payment' => DB::table('orders')->where('id', $orderId)->update(['payment_provider' => 'OFFLINE']),
            'already_refunded' => DB::table('orders')->where('id', $orderId)->update(['refund_status' => 'PARTIALLY_REFUNDED']),
            'refund_pending' => DB::table('orders')->where('id', $orderId)->update(['refund_status' => 'REFUND_PENDING']),
            'no_protection' => DB::table('products')->where('id', $this->option->id)->update(['is_refund_protection' => false]),
            'event_started' => DB::table('events')->where('id', $this->event->id)->update(['start_date' => now()->subHour()]),
            'deadline_passed' => $this->deadline(hours: 48, startsIn: 24),
            'all_cancelled' => DB::table('attendees')->whereIn('id', $attendeeIds)->update(['status' => 'CANCELLED']),
        };

        $sessionId = $this->openChat();
        $response = $this->requestCode($sessionId, $reference, $email)
            ->assertOk()
            ->assertJsonPath('data.step', 'unavailable')
            ->assertJsonPath('data.ask_contact', true);

        $this->assertStringContainsString(self::NEUTRAL, $response->json('data.messages.1.content'));
        Mail::assertNotQueued(LiveChatRefundCodeMail::class);

        $row = DB::table('live_chat_refund_requests')->orderByDesc('id')->first();
        $this->assertSame('REJECTED', $row->status);
        $this->assertSame($expectedReason, $row->failure_reason);
        $this->assertNull($row->code_hash);

        $session = DB::table('live_chat_sessions')->where('public_id', $sessionId)->first();
        $this->assertTrue((bool) $session->needs_follow_up);
        $this->assertStringStartsWith('Remboursement : ', $session->follow_up_reason);
    }

    public static function refusals(): array
    {
        return [
            'wrong e-mail' => ['wrong_email', 'ORDER_NOT_FOUND'],
            'unknown order' => ['unknown_order', 'ORDER_NOT_FOUND'],
            'order of another organizer' => ['other_organizer', 'ORDER_NOT_FOUND'],
            'order not completed' => ['not_completed', 'NOT_COMPLETED'],
            'not paid with Stripe' => ['offline_payment', 'NOT_PAID_WITH_STRIPE'],
            'already refunded' => ['already_refunded', 'ALREADY_REFUNDED'],
            'refund pending' => ['refund_pending', 'ALREADY_REFUNDED'],
            'no protection' => ['no_protection', 'NO_PROTECTION'],
            'event started' => ['event_started', 'EVENT_STARTED'],
            'deadline passed' => ['deadline_passed', 'DEADLINE_PASSED'],
            'no ticket left' => ['all_cancelled', 'NO_REFUNDABLE_TICKET'],
        ];
    }

    public function test_deadline_is_respected_when_the_event_is_far_enough(): void
    {
        [$orderId] = $this->order();
        $this->deadline(hours: 48, startsIn: 72);
        $sessionId = $this->openChat();

        $this->requestCode($sessionId, $this->publicId($orderId), self::BUYER_EMAIL)->assertJsonPath('data.step', 'code');
    }

    public function test_expired_code_is_refused(): void
    {
        [$orderId] = $this->order();
        $sessionId = $this->openChat();
        $this->requestCode($sessionId, $this->publicId($orderId), self::BUYER_EMAIL);
        $code = $this->sentCode();

        $this->travel(16)->minutes();

        $this->chat('post', '/public/live-chat/refund/verify', $sessionId, ['code' => $code])
            ->assertOk()
            ->assertJsonPath('data.step', 'code')
            ->assertJsonPath('data.attempts_left', 0)
            ->assertJsonPath('data.error', 'Ce code a expiré. Demandez-en un nouveau.');

        $this->assertSame('EXPIRED', DB::table('live_chat_refund_requests')->where('order_id', $orderId)->value('status'));
    }

    public function test_five_wrong_codes_block_the_request_and_hand_over(): void
    {
        DB::table('organizers')->where('id', $this->organizerId)->update(['email' => 'orga@example.com']);
        [$orderId] = $this->order();
        $sessionId = $this->openChat();
        $this->requestCode($sessionId, $this->publicId($orderId), self::BUYER_EMAIL);
        $code = $this->sentCode();
        $wrong = $code === '000000' ? '111111' : '000000';

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $this->chat('post', '/public/live-chat/refund/verify', $sessionId, ['code' => $wrong])
                ->assertJsonPath('data.attempts_left', 5 - $attempt);
        }

        $this->chat('post', '/public/live-chat/refund/verify', $sessionId, ['code' => $wrong])
            ->assertOk()
            ->assertJsonPath('data.step', 'unavailable')
            ->assertJsonPath('data.ask_contact', true);

        $this->assertSame('BLOCKED', DB::table('live_chat_refund_requests')->where('order_id', $orderId)->value('status'));

        $this->chat('post', '/public/live-chat/refund/verify', $sessionId, ['code' => $code])
            ->assertJsonPath('data.step', 'code')
            ->assertJsonPath('data.attempts_left', 0);
    }

    public function test_three_codes_per_order_and_per_day_at_most(): void
    {
        [$orderId] = $this->order();

        for ($send = 1; $send <= 3; $send++) {
            $this->requestCode($this->openChat(), $this->publicId($orderId), self::BUYER_EMAIL)->assertJsonPath('data.step', 'code');
        }

        $this->requestCode($this->openChat(), $this->publicId($orderId), self::BUYER_EMAIL)
            ->assertJsonPath('data.step', 'unavailable');

        Mail::assertQueued(LiveChatRefundCodeMail::class, 3);
    }

    public function test_requests_are_limited_per_ip(): void
    {
        config(['services.live_chat.refund_max_requests_per_ip_per_hour' => 2]);
        [$orderId] = $this->order();
        $sessionId = $this->openChat();

        $this->requestCode($sessionId, 'O-NOTHING', self::BUYER_EMAIL)->assertOk();
        $this->requestCode($sessionId, 'O-NOTHING2', self::BUYER_EMAIL)->assertOk();
        $this->requestCode($sessionId, $this->publicId($orderId), self::BUYER_EMAIL)
            ->assertStatus(429)
            ->assertJsonPath('contact_form_fallback', true);
    }

    public function test_amount_above_the_cap_is_handed_over_without_refund(): void
    {
        config(['services.live_chat.auto_refund_max_amount' => 10]);
        DB::table('organizers')->where('id', $this->organizerId)->update(['email' => 'orga@example.com']);
        [$orderId, $attendeeIds] = $this->order();
        $sessionId = $this->verifiedChat($orderId);

        $this->stripeRefunds->shouldNotReceive('refundPayment');

        $this->chat('post', '/public/live-chat/refund/confirm', $sessionId, ['attendee_ids' => [$attendeeIds[0]]])
            ->assertOk()
            ->assertJsonPath('data.step', 'unavailable')
            ->assertJsonPath('data.ask_contact', false);

        $this->assertSame('ACTIVE', DB::table('attendees')->where('id', $attendeeIds[0])->value('status'));
        $this->assertSame('HANDED_OFF', DB::table('live_chat_refund_requests')->where('order_id', $orderId)->value('status'));
        Mail::assertQueued(LiveChatContactAlertMail::class, fn (LiveChatContactAlertMail $mail) => $mail->hasTo('orga@example.com'));
        Mail::assertNotQueued(OrderRefunded::class);
    }

    public function test_daily_cap_per_organizer_is_handed_over_without_refund(): void
    {
        [$orderId, $attendeeIds] = $this->order();
        $sessionId = $this->verifiedChat($orderId);

        for ($i = 0; $i < 10; $i++) {
            DB::table('live_chat_refund_requests')->insert([
                'organizer_id' => $this->organizerId,
                'status' => 'REFUNDED',
                'amount' => 15,
                'refunded_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->stripeRefunds->shouldNotReceive('refundPayment');

        $this->chat('post', '/public/live-chat/refund/confirm', $sessionId, ['attendee_ids' => [$attendeeIds[0]]])
            ->assertOk()
            ->assertJsonPath('data.step', 'unavailable');

        $this->assertSame('ACTIVE', DB::table('attendees')->where('id', $attendeeIds[0])->value('status'));
    }

    public function test_stripe_error_cancels_nothing_and_hands_over(): void
    {
        DB::table('organizers')->where('id', $this->organizerId)->update(['email' => 'orga@example.com']);
        [$orderId, $attendeeIds] = $this->order();
        $sessionId = $this->verifiedChat($orderId);

        $this->stripeRefunds->shouldReceive('refundPayment')->once()->andThrow(new RuntimeException('card_declined'));

        $this->chat('post', '/public/live-chat/refund/confirm', $sessionId, ['attendee_ids' => [$attendeeIds[0]]])
            ->assertOk()
            ->assertJsonPath('data.step', 'unavailable');

        $this->assertSame('ACTIVE', DB::table('attendees')->where('id', $attendeeIds[0])->value('status'));
        $this->assertNull(DB::table('orders')->where('id', $orderId)->value('refund_status'));
        $this->assertSame('FAILED', DB::table('live_chat_refund_requests')->where('order_id', $orderId)->value('status'));
        Mail::assertQueued(LiveChatContactAlertMail::class);
        Mail::assertNotQueued(LiveChatRefundAlertMail::class);
    }

    public function test_tickets_beyond_the_protections_bought_are_refused(): void
    {
        [$orderId, $attendeeIds] = $this->order(tickets: 2, options: 1);
        $sessionId = $this->verifiedChat($orderId, expectedMaxTickets: 1);

        $this->stripeRefunds->shouldNotReceive('refundPayment');

        $this->chat('post', '/public/live-chat/refund/confirm', $sessionId, ['attendee_ids' => $attendeeIds])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('attendee_ids');

        $this->assertSame('VERIFIED', DB::table('live_chat_refund_requests')->where('order_id', $orderId)->value('status'));
    }

    public function test_confirm_without_a_checked_code_is_refused(): void
    {
        [, $attendeeIds] = $this->order();
        $sessionId = $this->openChat();

        $this->stripeRefunds->shouldNotReceive('refundPayment');

        $this->chat('post', '/public/live-chat/refund/confirm', $sessionId, ['attendee_ids' => [$attendeeIds[0]]])
            ->assertUnprocessable();
    }

    private function verifiedChat(int $orderId, int $expectedMaxTickets = 1): string
    {
        $sessionId = $this->openChat();
        $this->requestCode($sessionId, $this->publicId($orderId), self::BUYER_EMAIL)->assertJsonPath('data.step', 'code');
        $this->chat('post', '/public/live-chat/refund/verify', $sessionId, ['code' => $this->sentCode()])
            ->assertJsonPath('data.step', 'tickets')
            ->assertJsonPath('data.max_tickets', $expectedMaxTickets);

        return $sessionId;
    }

    /**
     * An order paid by card: tickets at 15 € + 0,99 € of platform fee each, and the protection at 0,49 € per ticket.
     *
     * @return array{0: int, 1: int[]}
     */
    private function order(int $tickets = 1, int $options = 1): array
    {
        $fees = round(0.99 * $tickets, 2);
        $total = round(15 * $tickets + $fees + 0.49 * $options, 2);
        $orderId = DB::table('orders')->insertGetId([
            'short_id' => 'o_'.Str::random(13),
            'public_id' => 'O-'.strtoupper(Str::random(7)),
            'event_id' => $this->event->id,
            'total_before_additions' => 15 * $tickets + 0.49 * $options,
            'total_gross' => $total,
            'total_fee' => $fees,
            'currency' => 'EUR',
            'first_name' => 'Awa',
            'last_name' => 'Saïd',
            'email' => self::BUYER_EMAIL,
            'status' => 'COMPLETED',
            'payment_status' => 'PAYMENT_RECEIVED',
            'payment_provider' => 'STRIPE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('order_items')->insert([
            [
                'order_id' => $orderId,
                'product_id' => $this->ticket->id,
                'product_price_id' => $this->ticketPrice->id,
                'item_name' => 'Plein tarif',
                'quantity' => $tickets,
                'price' => 15,
                'price_before_discount' => 15,
                'total_before_additions' => 15 * $tickets,
                'total_service_fee' => $fees,
                'total_tax' => 0,
                'total_gross' => 15 * $tickets + $fees,
                'product_type' => 'TICKET',
            ],
            [
                'order_id' => $orderId,
                'product_id' => $this->option->id,
                'product_price_id' => $this->optionPrice->id,
                'item_name' => 'Option sérénité / billet',
                'quantity' => $options,
                'price' => 0.49,
                'price_before_discount' => 0.49,
                'total_before_additions' => 0.49 * $options,
                'total_service_fee' => 0,
                'total_tax' => 0,
                'total_gross' => 0.49 * $options,
                'product_type' => 'GENERAL',
            ],
        ]);

        $attendeeIds = [];
        for ($i = 0; $i < $tickets; $i++) {
            $attendeeIds[] = DB::table('attendees')->insertGetId([
                'short_id' => 'a_'.Str::random(13),
                'public_id' => 'A-'.strtoupper(Str::random(7)),
                'first_name' => 'Awa',
                'last_name' => 'Saïd',
                'email' => self::BUYER_EMAIL,
                'order_id' => $orderId,
                'product_id' => $this->ticket->id,
                'product_price_id' => $this->ticketPrice->id,
                'event_id' => $this->event->id,
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('stripe_payments')->insert([
            'order_id' => $orderId,
            'payment_intent_id' => 'pi_'.Str::random(10),
            'connected_account_id' => 'acct_test',
            'application_fee_gross' => (int) round($fees * 100),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$orderId, $attendeeIds];
    }

    private function moveOrderToAnotherOrganizer(int $orderId): void
    {
        [$otherEvent] = $this->createEventWithProduct();
        DB::table('orders')->where('id', $orderId)->update(['event_id' => $otherEvent->id]);
    }

    private function deadline(int $hours, int $startsIn): void
    {
        DB::table('event_settings')->where('event_id', $this->event->id)->update(['refund_protection_deadline_hours' => $hours]);
        DB::table('events')->where('id', $this->event->id)->update(['start_date' => now()->addHours($startsIn)]);
    }

    private function publicId(int $orderId): string
    {
        return (string) DB::table('orders')->where('id', $orderId)->value('public_id');
    }

    private function sentCode(): string
    {
        $code = null;
        Mail::assertQueued(LiveChatRefundCodeMail::class, function (LiveChatRefundCodeMail $mail) use (&$code) {
            $code = (fn () => $this->code)->call($mail);

            return true;
        });

        return (string) $code;
    }

    private function answer(string $reply, string $intent = 'none', bool $withFollowUp = false): void
    {
        $this->claude->shouldReceive('askWithUsage')->once()->andReturn(new ClaudeStructuredAnswerDTO(
            data: [
                'reply' => $reply,
                'needs_follow_up' => $withFollowUp,
                'ask_contact' => $withFollowUp,
                'reason' => $withFollowUp ? 'remboursement' : null,
                'intent' => $intent,
            ],
            model: 'claude-haiku-5-5',
            inputTokens: 100,
            outputTokens: 20,
        ));
    }

    private function requestCode(string $sessionId, string $reference, string $email): TestResponse
    {
        return $this->chat('post', '/public/live-chat/refund/request', $sessionId, [
            'order_reference' => $reference,
            'email' => $email,
        ]);
    }

    private function openChat(): string
    {
        return (string) $this->postJson("/public/organizers/{$this->organizerId}/live-chat/sessions", ['event_id' => $this->event->id])
            ->assertCreated()
            ->json('data.session_id');
    }

    private function chat(string $method, string $uri, string $sessionId, array $data = []): TestResponse
    {
        return $this->withHeaders(['X-Chat-Session' => $sessionId])->json(strtoupper($method), $uri, $data);
    }
}
