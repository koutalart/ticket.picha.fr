<?php

declare(strict_types=1);

namespace Tests\Unit\LiveChat;

use HiEvents\DomainObjects\Enums\OrganizerTeamContactRole;
use HiEvents\Exceptions\EventAssistantFailedException;
use HiEvents\Jobs\Sms\SendSmsInboundAlertJob;
use HiEvents\Mail\LiveChat\LiveChatRefundAlertMail;
use HiEvents\Mail\LiveChat\LiveChatRefundCodeMail;
use HiEvents\Mail\Order\OrderRefunded;
use HiEvents\Mail\Sms\PlatformSupervisionAlertMail;
use HiEvents\Models\Event;
use HiEvents\Models\OrganizerSmsSetting;
use HiEvents\Models\Product;
use HiEvents\Models\ProductPrice;
use HiEvents\Services\Application\Handlers\Sms\DTO\HttpSmsWebhookDTO;
use HiEvents\Services\Application\Handlers\Sms\HandleHttpSmsWebhookHandler;
use HiEvents\Services\Domain\LiveChat\Refund\Approval\RefundApprovalLinkService;
use HiEvents\Services\Domain\LiveChat\Refund\Approval\RefundApprovalSchedule;
use HiEvents\Services\Domain\LiveChat\Refund\Approval\RefundApprovalService;
use HiEvents\Services\Domain\LiveChat\Refund\Approval\RefundCustomerUpdateGenerator;
use HiEvents\Services\Domain\Payment\Stripe\StripePaymentIntentRefundService;
use HiEvents\Services\Domain\Sms\HttpSmsWebhookSignatureVerifier;
use HiEvents\Services\Domain\Sms\Team\OrganizerTeamContactService;
use HiEvents\Services\Infrastructure\AI\ClaudeStructuredOutputClient;
use HiEvents\Services\Infrastructure\AI\DTO\ClaudeStructuredAnswerDTO;
use HiEvents\Services\Infrastructure\HttpSms\HttpSmsClient;
use HiEvents\Services\Infrastructure\Stripe\StripeClientFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery;
use Mockery\MockInterface;
use Stripe\Refund;
use Stripe\StripeClient;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

/**
 * Refunds from the web chat wait for the organizer team: by SMS, by signed link or from the dashboard.
 */
class LiveChatRefundApprovalTest extends TestCase
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

    private const LINE_NUMBER = '+262639000000';

    private const RESPONSABLE = '+262639689691';

    private const COPY = '+262639250206';

    private const SUPERVISOR = '+262639780773';

    private const VISITOR = '+262639444555';

    private const OUTSIDER = '+262639999888';

    private array $sent = [];

    private ?OrganizerSmsSetting $line = null;

    private function withSmsLine(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-26 07:00:00', 'UTC'));
        config(['services.platform_supervisor.phones' => self::SUPERVISOR, 'app.frontend_url' => 'https://ticket.picha.test']);
        DB::table('organizer_sms_settings')->update(['api_key' => null, 'shared_line_settings_id' => null]);
        DB::table('organizers')->where('id', $this->organizerId)->update(['email' => 'orga@example.com']);

        $this->line = OrganizerSmsSetting::create([
            'account_id' => $this->event->account_id,
            'organizer_id' => $this->organizerId,
            'webhook_token' => Str::random(40),
            'webhook_signing_key' => 'key',
            'api_key' => 'uk_line',
            'from_number' => self::LINE_NUMBER,
        ]);

        $this->mock(HttpSmsClient::class, function (MockInterface $client) {
            $client->shouldReceive('sendMessage')->andReturnUsing(function (string $apiKey, string $from, string $to, string $content) {
                $this->sent[] = compact('to', 'content');

                return 'provider-out-'.Str::random(16);
            });
        });
        $this->mock(HttpSmsWebhookSignatureVerifier::class)->shouldReceive('verify');

        $teams = app(OrganizerTeamContactService::class);
        $teams->save($this->organizerId, self::RESPONSABLE, 'Responsable', OrganizerTeamContactRole::ORGANIZER);
        $teams->save($this->organizerId, self::COPY, 'Innocent', OrganizerTeamContactRole::COPY);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_nothing_is_refunded_before_approval_and_the_team_gets_the_request_at_once(): void
    {
        $this->withSmsLine();
        [$orderId, $attendeeIds] = $this->order();
        $this->stripeRefunds->shouldNotReceive('refundPayment');

        $requestId = $this->submit($orderId, $attendeeIds[0]);

        $this->assertSame('ACTIVE', DB::table('attendees')->where('id', $attendeeIds[0])->value('status'));
        $this->assertNull(DB::table('orders')->where('id', $orderId)->value('refund_status'));
        $row = DB::table('live_chat_refund_requests')->where('id', $requestId)->first();
        $this->assertSame('AWAITING_APPROVAL', $row->status);
        $this->assertSame(self::VISITOR, $row->customer_phone);

        $this->assertSame(self::VISITOR, $this->sent[0]['to']);
        $this->assertStringContainsString('R'.$requestId.' de 15,00 € est bien enregistrée', $this->sent[0]['content']);

        $toTeam = array_values(array_filter($this->sent, fn ($sms) => in_array($sms['to'], [self::RESPONSABLE, self::COPY], true)));
        $this->assertCount(2, $toTeam);
        $this->assertStringContainsString("Remboursement R{$requestId} à valider\nClient : Awa Saïd", $toTeam[0]['content']);
        $this->assertStringContainsString("Montant : 15,00 €\nRépondez OUI R{$requestId} ou NON R{$requestId}", $toTeam[0]['content']);
        $this->assertStringContainsString('https://ticket.picha.test/refund-approval/'.$requestId.'?token=', $toTeam[0]['content']);
        Mail::assertNotQueued(OrderRefunded::class);
    }

    public function test_oui_by_sms_from_a_team_number_refunds_and_confirms(): void
    {
        $this->withSmsLine();
        [$orderId, $attendeeIds] = $this->order();
        $requestId = $this->submit($orderId, $attendeeIds[0]);
        $this->stripeRefunds->shouldReceive('refundPayment')->once()->andReturn(Refund::constructFrom(['id' => 're_test']));
        $this->sent = [];

        $this->receive(self::COPY, '  oui  r '.$requestId.' ', 'in-1');

        $row = DB::table('live_chat_refund_requests')->where('id', $requestId)->first();
        $this->assertSame('REFUNDED', $row->status);
        $this->assertSame('APPROVED', $row->decision);
        $this->assertSame('SMS', $row->decided_by_channel);
        $this->assertSame(self::COPY, $row->decided_by_phone);
        $this->assertSame('CANCELLED', DB::table('attendees')->where('id', $attendeeIds[0])->value('status'));

        $this->assertSame(self::VISITOR, $this->sent[0]['to']);
        $this->assertStringContainsString('Bonne nouvelle', $this->sent[0]['content']);
        $this->assertSame(self::COPY, $this->sent[1]['to']);
        $this->assertStringContainsString('R'.$requestId.' validée : 15,00 € remboursés', $this->sent[1]['content']);

        $this->assertSame(
            ['SUBMITTED', 'CUSTOMER_NOTIFIED', 'VALIDATORS_NOTIFIED', 'APPROVED', 'REFUNDED'],
            DB::table('live_chat_refund_request_logs')->where('live_chat_refund_request_id', $requestId)->orderBy('id')->pluck('action')->all(),
        );
        Mail::assertQueued(LiveChatRefundAlertMail::class, fn (LiveChatRefundAlertMail $mail) => $mail->hasTo('orga@example.com'));
    }

    public function test_non_by_sms_refuses_with_tact(): void
    {
        $this->withSmsLine();
        [$orderId, $attendeeIds] = $this->order();
        $requestId = $this->submit($orderId, $attendeeIds[0]);
        $this->stripeRefunds->shouldNotReceive('refundPayment');
        $this->sent = [];

        $this->receive(self::RESPONSABLE, 'NON R'.$requestId, 'in-1');

        $this->assertSame('REFUSED', DB::table('live_chat_refund_requests')->where('id', $requestId)->value('status'));
        $this->assertSame('ACTIVE', DB::table('attendees')->where('id', $attendeeIds[0])->value('status'));
        $this->assertStringContainsString("ne peut pas l'accepter", $this->sent[0]['content']);
        $this->assertSame(self::RESPONSABLE, $this->sent[1]['to']);
        $this->assertStringContainsString('refusée', $this->sent[1]['content']);
    }

    public function test_a_number_outside_the_team_cannot_decide(): void
    {
        $this->withSmsLine();
        [$orderId, $attendeeIds] = $this->order();
        $requestId = $this->submit($orderId, $attendeeIds[0]);
        $this->stripeRefunds->shouldNotReceive('refundPayment');
        $this->claude->shouldReceive('askWithUsage')->andThrow(new EventAssistantFailedException('customer assistant'));

        $this->receive(self::OUTSIDER, 'OUI R'.$requestId, 'in-1');
        $this->receive(self::VISITOR, 'oui R'.$requestId, 'in-2');

        $this->assertSame('AWAITING_APPROVAL', DB::table('live_chat_refund_requests')->where('id', $requestId)->value('status'));
        $this->assertSame('ACTIVE', DB::table('attendees')->where('id', $attendeeIds[0])->value('status'));
    }

    public function test_signed_link_works_once_and_the_first_decision_wins(): void
    {
        $this->withSmsLine();
        [$orderId, $attendeeIds] = $this->order();
        $requestId = $this->submit($orderId, $attendeeIds[0]);
        $this->stripeRefunds->shouldNotReceive('refundPayment');
        $token = app(RefundApprovalLinkService::class)->token($requestId);

        $this->getJson("/public/refund-approvals/{$requestId}?token=bad")->assertForbidden();
        $this->getJson("/public/refund-approvals/{$requestId}?token={$token}")
            ->assertOk()
            ->assertJsonPath('data.reference', 'R'.$requestId)
            ->assertJsonPath('data.amount_text', '15,00 €')
            ->assertJsonPath('data.can_decide', true);

        $this->postJson("/public/refund-approvals/{$requestId}/decision", ['token' => $token, 'decision' => 'REFUSED'])
            ->assertOk()
            ->assertJsonPath('data.status', 'REFUSED')
            ->assertJsonPath('data.can_decide', false);
        $this->postJson("/public/refund-approvals/{$requestId}/decision", ['token' => $token, 'decision' => 'APPROVED'])
            ->assertStatus(409);

        $this->sent = [];
        $this->receive(self::RESPONSABLE, 'OUI R'.$requestId, 'in-1');
        $this->assertStringContainsString('déjà été traitée', $this->sent[0]['content']);

        $row = DB::table('live_chat_refund_requests')->where('id', $requestId)->first();
        $this->assertSame('REFUSED', $row->status);
        $this->assertSame('LINK', $row->decided_by_channel);
        $this->assertNotNull($row->approval_link_used_at);

        Carbon::setTestNow(Carbon::now('UTC')->addHours(73));
        $this->getJson("/public/refund-approvals/{$requestId}?token={$token}")->assertForbidden();
    }

    public function test_reminder_times_stay_between_8_and_21_in_mayotte(): void
    {
        $local = fn (string $time) => Carbon::parse($time, 'Indian/Mayotte');
        $inMayotte = fn (Carbon $moment) => $moment->copy()->setTimezone('Indian/Mayotte')->format('Y-m-d H:i');

        $this->assertSame('2026-10-24 08:00', $inMayotte(RefundApprovalSchedule::withinDay($local('2026-10-24 06:30'))));
        $this->assertSame('2026-10-24 20:59', $inMayotte(RefundApprovalSchedule::withinDay($local('2026-10-24 20:59'))));
        $this->assertSame('2026-10-25 08:00', $inMayotte(RefundApprovalSchedule::withinDay($local('2026-10-24 21:00'))));
        $this->assertSame('2026-10-24 12:00', $inMayotte(RefundApprovalSchedule::nextReminderAt($local('2026-10-24 10:00'), 1)));
        $this->assertSame('2026-10-24 18:00', $inMayotte(RefundApprovalSchedule::nextReminderAt($local('2026-10-24 12:00'), 2)));
        $this->assertSame('2026-10-25 08:00', $inMayotte(RefundApprovalSchedule::nextReminderAt($local('2026-10-24 19:30'), 1)));
        $this->assertSame('2026-10-25 08:00', $inMayotte(RefundApprovalSchedule::nextCustomerUpdateAt($local('2026-10-24 10:00'))));
        $this->assertSame('2026-10-24 20:00', $inMayotte(RefundApprovalSchedule::nextCustomerUpdateAt($local('2026-10-24 08:00'))));
    }

    public function test_a_request_made_at_night_reaches_the_team_at_8(): void
    {
        $this->withSmsLine();
        Carbon::setTestNow(Carbon::parse('2026-10-26 20:30:00', 'UTC'));
        [$orderId, $attendeeIds] = $this->order();

        $requestId = $this->submit($orderId, $attendeeIds[0]);

        $this->assertSame([self::VISITOR], array_column($this->sent, 'to'));
        $this->assertSame('2026-10-27 05:00:00', DB::table('live_chat_refund_requests')->where('id', $requestId)->value('next_reminder_at'));
    }

    public function test_reminders_and_news_follow_the_schedule_and_the_alert_comes_after_24_hours(): void
    {
        $this->withSmsLine();
        [$orderId, $attendeeIds] = $this->order();
        $requestId = $this->submit($orderId, $attendeeIds[0]);
        $service = app(RefundApprovalService::class);
        $this->claude->shouldReceive('askWithUsage')->andReturn(new ClaudeStructuredAnswerDTO(
            data: ['message' => 'Votre demande R'.$requestId.' est toujours chez l\'organisateur, je vous tiens au courant.'],
            model: 'claude-haiku-5-5',
            inputTokens: 50,
            outputTokens: 20,
        ));
        $this->sent = [];
        $start = Carbon::parse('2026-10-26 07:00:00', 'UTC');

        $run = function (string $after) use ($service, $start) {
            Carbon::setTestNow($start->copy()->add($after));

            return $service->processDue();
        };

        $this->assertSame(0, $run('1 hour 59 minutes')->validatorReminders);
        $result = $run('2 hours');
        $this->assertSame(1, $result->validatorReminders);
        $this->assertStringStartsWith('Relance : remboursement R'.$requestId.' à valider', $this->sent[0]['content']);
        $this->assertSame(0, $run('2 hours 1 minute')->validatorReminders);
        $this->assertSame(1, $run('8 hours')->validatorReminders);
        $this->assertSame(0, $run('11 hours')->validatorReminders);

        $this->assertSame(0, $run('12 hours')->customerUpdates);
        $result = $run('22 hours');
        $this->assertSame(1, $result->customerUpdates);
        $this->assertSame(1, $result->validatorReminders);
        $this->assertSame(0, $result->escalations);
        $update = collect($this->sent)->firstWhere('to', self::VISITOR);
        $this->assertStringContainsString('toujours chez l\'organisateur', $update['content']);
        $this->assertSame(0, $run('23 hours')->customerUpdates);

        $result = $run('24 hours');
        $this->assertSame(1, $result->escalations);
        $this->assertContains(self::SUPERVISOR, array_column($this->sent, 'to'));
        Mail::assertQueued(PlatformSupervisionAlertMail::class, fn (PlatformSupervisionAlertMail $mail) => $mail->hasTo('veille@picha.test'));
        $this->assertSame(0, $run('25 hours')->escalations);

        $updates = DB::table('live_chat_refund_requests')->where('id', $requestId)->value('customer_updates_count');
        $this->assertSame(1, (int) $updates);
    }

    public function test_news_falls_back_on_fixed_variants_that_change_each_time(): void
    {
        $first = RefundCustomerUpdateGenerator::fallback('R12', 1, 'fr');
        $second = RefundCustomerUpdateGenerator::fallback('R12', 2, 'fr');
        $tu = RefundCustomerUpdateGenerator::fallback('R12', 1, 'fr_TU');

        $this->assertNotSame($first, $second);
        $this->assertStringContainsString('R12', $first);
        $this->assertStringContainsString('Votre', $first);
        $this->assertStringContainsString('Ta demande', $tu);
    }

    public function test_sms_decision_parsing_is_tolerant(): void
    {
        $this->assertSame(12, RefundApprovalService::parseSmsDecision(' Oui R12 ')->requestId);
        $this->assertSame('REFUSED', RefundApprovalService::parseSmsDecision('non r 7!')->decision->name);
        $this->assertNull(RefundApprovalService::parseSmsDecision('oui merci'));
        $this->assertNull(RefundApprovalService::parseSmsDecision('OUI R12 et R13'));
    }

    private function submit(int $orderId, int $attendeeId): int
    {
        $sessionId = $this->verifiedChat($orderId);
        DB::table('live_chat_sessions')->where('public_id', $sessionId)->update(['visitor_phone' => self::VISITOR]);

        $this->chat('post', '/public/live-chat/refund/confirm', $sessionId, ['attendee_ids' => [$attendeeId]])
            ->assertOk()
            ->assertJsonPath('data.step', 'pending')
            ->assertJsonPath('data.amount', 15);

        return (int) DB::table('live_chat_refund_requests')->where('order_id', $orderId)->orderByDesc('id')->value('id');
    }

    private function receive(string $contact, string $content, string $messageId): void
    {
        app(HandleHttpSmsWebhookHandler::class)->handle(new HttpSmsWebhookDTO(
            webhookToken: $this->line->webhook_token,
            bearerToken: 'signed',
            eventType: HandleHttpSmsWebhookHandler::EVENT_RECEIVED,
            payload: json_encode(['data' => [
                'message_id' => $messageId,
                'contact' => $contact,
                'owner' => self::LINE_NUMBER,
                'content' => $content,
                'timestamp' => Carbon::now('UTC')->toIso8601String(),
            ]]),
        ));
    }

    private function approve(int $orderId): TestResponse
    {
        $requestId = (int) DB::table('live_chat_refund_requests')->where('order_id', $orderId)->orderByDesc('id')->value('id');

        return $this->withHeader('Authorization', 'Bearer '.$this->loginAndGetToken($this->user, self::PASSWORD))
            ->postJson("/organizers/{$this->organizerId}/refund-approvals/{$requestId}/decision", ['decision' => 'APPROVED']);
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
