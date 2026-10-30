<?php

declare(strict_types=1);

namespace Tests\Unit\LiveChat;

use Carbon\Carbon;
use HiEvents\DomainObjects\SmsMessageDomainObject;
use HiEvents\Helper\IdHelper;
use HiEvents\Helper\KioskSentinelEmail;
use HiEvents\Jobs\Sms\SendSmsInboundAlertJob;
use HiEvents\Models\Event;
use HiEvents\Services\Domain\LiveChat\TicketResend\LiveChatTicketResendService;
use HiEvents\Services\Domain\SelfService\SelfServiceResendEmailService;
use HiEvents\Services\Domain\Sms\DTO\SendSmsDTO;
use HiEvents\Services\Domain\Sms\SmsSendService;
use HiEvents\Services\Infrastructure\AI\ClaudeStructuredOutputClient;
use HiEvents\Services\Infrastructure\AI\DTO\ClaudeStructuredAnswerDTO;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery;
use Mockery\MockInterface;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

class LiveChatTicketResendTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    private const BUYER_EMAIL = 'elodie.m@gmail.com';

    private const VISITOR_MESSAGE = 'Je suis Élodie, mon numéro 0639 12 34 56';

    private Event $event;

    private int $organizerId;

    private ClaudeStructuredOutputClient|MockInterface $claude;

    private SelfServiceResendEmailService|MockInterface $resendService;

    /** @var list<SendSmsDTO> */
    private array $sentSms = [];

    protected function setUp(): void
    {
        parent::setUp();

        [$this->event] = $this->createEventWithProduct();
        $this->organizerId = (int) $this->event->organizer_id;

        config([
            'services.anthropic.api_key' => 'test-key',
            'services.live_chat.model' => 'claude-haiku-5-5',
        ]);

        $this->claude = Mockery::mock(ClaudeStructuredOutputClient::class);
        $this->claude->shouldReceive('isConfigured')->andReturn(true)->byDefault();
        $this->claude->shouldReceive('askWithUsage')->andReturn($this->resendAnswer('Élodie', '0639 12 34 56'))->byDefault();
        $this->app->instance(ClaudeStructuredOutputClient::class, $this->claude);

        $sms = Mockery::mock(SmsSendService::class);
        $sms->shouldReceive('send')->andReturnUsing(function (SendSmsDTO $dto) {
            $this->sentSms[] = $dto;

            return new SmsMessageDomainObject;
        });
        $this->app->instance(SmsSendService::class, $sms);

        $this->resendService = Mockery::mock(SelfServiceResendEmailService::class);
        $this->app->instance(SelfServiceResendEmailService::class, $this->resendService);

        Bus::fake([SendSmsInboundAlertJob::class]);
        Mail::fake();
        $this->settings();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_matching_order_gets_a_code_by_sms_and_its_tickets_again_with_the_code(): void
    {
        $orderId = $this->order(firstName: 'elodie', phone: '+262 639 12 34 56');
        $sessionId = $this->openChat();

        $this->say($sessionId, self::VISITOR_MESSAGE)
            ->assertOk()
            ->assertJsonPath('data.messages.1.content', 'Si ces infos correspondent à une commande, vous allez recevoir un code par SMS. Écrivez-le-moi ici.');

        $this->assertCount(1, $this->sentSms);
        $this->assertSame('+262639123456', $this->sentSms[0]->phone);
        $this->assertSame($this->organizerId, $this->sentSms[0]->organizerId);
        $code = $this->sentCode();
        $this->assertStringNotContainsString($code, (string) DB::table('live_chat_ticket_resend_requests')->value('code_hash'));

        $this->resendService->shouldReceive('resendOrderConfirmation')
            ->once()
            ->with($orderId, $this->event->id, Mockery::type('string'), 'PICHA live chat');

        $response = $this->say($sessionId, substr($code, 0, 3).' '.substr($code, 3))->assertOk();

        $reply = (string) $response->json('data.messages.1.content');
        $this->assertSame("C'est fait, je viens de renvoyer vos billets à e***@g***.com. Pensez à regarder dans les spams.", $reply);
        $this->assertStringNotContainsString(self::BUYER_EMAIL, $reply);
        $this->assertSame('RESENT', DB::table('live_chat_ticket_resend_requests')->value('status'));
        $this->assertNull(DB::table('live_chat_ticket_resend_requests')->value('code_hash'));
    }

    public function test_no_matching_order_gets_the_same_reply_and_no_sms(): void
    {
        $this->order(firstName: 'Awa', phone: '0639123456');
        $sessionId = $this->openChat();

        $this->say($sessionId, self::VISITOR_MESSAGE)
            ->assertOk()
            ->assertJsonPath('data.messages.1.content', 'Si ces infos correspondent à une commande, vous allez recevoir un code par SMS. Écrivez-le-moi ici.');

        $this->assertSame([], $this->sentSms);
        $this->resendService->shouldNotReceive('resendOrderConfirmation');

        $this->say($sessionId, '123456')
            ->assertOk()
            ->assertJsonPath('data.messages.1.content', "Ce code n'est pas le bon. Essais restants : 2.");
    }

    public function test_paper_tickets_sur_place_are_never_sent(): void
    {
        $this->order(firstName: 'Élodie', phone: '0639123456', email: KioskSentinelEmail::forBoxOfficeSale(12));
        $this->order(firstName: 'Élodie', phone: '0639123456', paymentStatus: 'AWAITING_OFFLINE_PAYMENT', provider: 'OFFLINE', manual: true);
        $this->order(firstName: 'Élodie', phone: '0639123456', status: 'AWAITING_OFFLINE_PAYMENT');
        $sessionId = $this->openChat();

        $this->say($sessionId, self::VISITOR_MESSAGE)->assertOk();

        $this->assertSame([], $this->sentSms);
        $this->assertSame('[]', DB::table('live_chat_ticket_resend_requests')->value('order_ids'));
    }

    public function test_order_of_another_event_is_ignored_when_the_chat_is_on_an_event_page(): void
    {
        $otherEvent = Event::create([
            'title' => 'Autre soirée',
            'account_id' => $this->event->account_id,
            'organizer_id' => $this->organizerId,
            'currency' => 'EUR',
            'status' => 'LIVE',
            'short_id' => IdHelper::shortId(IdHelper::EVENT_PREFIX),
        ]);
        $this->order(firstName: 'Élodie', phone: '0639123456', eventId: $otherEvent->id);

        $this->say($this->openChat($this->event->id), self::VISITOR_MESSAGE)->assertOk();
        $this->assertSame([], $this->sentSms);

        $this->say($this->openChat(), self::VISITOR_MESSAGE)->assertOk();
        $this->assertCount(1, $this->sentSms);
    }

    public function test_three_wrong_codes_block_the_request(): void
    {
        $this->order(firstName: 'Élodie', phone: '0639123456');
        $sessionId = $this->openChat();
        $this->say($sessionId, self::VISITOR_MESSAGE);
        $wrong = $this->sentCode() === '000000' ? '111111' : '000000';
        $this->resendService->shouldNotReceive('resendOrderConfirmation');

        $this->say($sessionId, $wrong)->assertJsonPath('data.messages.1.content', "Ce code n'est pas le bon. Essais restants : 2.");
        $this->say($sessionId, $wrong)->assertJsonPath('data.messages.1.content', "Ce code n'est pas le bon. Essais restants : 1.");
        $this->say($sessionId, $wrong)->assertJsonPath('data.messages.1.content', 'Trop de codes incorrects. Redonnez-moi votre prénom et votre numéro pour recevoir un nouveau code.');

        $this->assertSame('BLOCKED', DB::table('live_chat_ticket_resend_requests')->value('status'));
        $this->assertSame(3, (int) DB::table('live_chat_ticket_resend_requests')->value('attempts'));
    }

    public function test_code_expires_after_twenty_minutes(): void
    {
        $start = Carbon::now('UTC')->startOfMinute();
        Carbon::setTestNow($start);
        $this->order(firstName: 'Élodie', phone: '0639123456');
        $sessionId = $this->openChat();
        $this->say($sessionId, self::VISITOR_MESSAGE);
        $this->resendService->shouldNotReceive('resendOrderConfirmation');

        Carbon::setTestNow($start->copy()->addMinutes(20));
        $this->say($sessionId, $this->sentCode())
            ->assertJsonPath('data.messages.1.content', 'Ce code a expiré. Redonnez-moi votre prénom et votre numéro pour en recevoir un nouveau.');

        $this->assertSame('EXPIRED', DB::table('live_chat_ticket_resend_requests')->value('status'));
    }

    public function test_three_codes_at_most_per_phone_in_a_chat(): void
    {
        $this->order(firstName: 'Élodie', phone: '0639123456');
        $sessionId = $this->openChat();

        for ($i = 0; $i < LiveChatTicketResendService::MAX_SENDS_PER_PHONE_PER_SESSION; $i++) {
            $this->say($sessionId, self::VISITOR_MESSAGE)->assertOk();
        }
        $this->say($sessionId, self::VISITOR_MESSAGE)
            ->assertOk()
            ->assertJsonPath('data.messages.1.content', "Plusieurs codes ont déjà été demandés. Je transmets votre demande à l'organisateur, il va regarder ça.");

        $this->assertCount(3, $this->sentSms);
        $this->assertTrue((bool) DB::table('live_chat_sessions')->where('public_id', $sessionId)->value('needs_follow_up'));
        $this->assertSame(1, DB::table('live_chat_ticket_resend_requests')->where('status', 'CODE_SENT')->count());
    }

    public function test_a_number_the_visitor_did_not_write_is_never_used(): void
    {
        $this->order(firstName: 'Élodie', phone: '0639123456');
        $this->claude->shouldReceive('askWithUsage')->andReturn($this->resendAnswer('Élodie', '0639 12 34 56'));

        $this->say($this->openChat(), "C'est Élodie, j'ai pas reçu mon billet")
            ->assertOk()
            ->assertJsonPath('data.messages.1.content', 'Pour vous renvoyer vos billets, il me faut votre prénom et le numéro de téléphone utilisé pour la commande.');

        $this->assertSame([], $this->sentSms);
        $this->assertSame(0, DB::table('live_chat_ticket_resend_requests')->count());
    }

    public function test_assistant_asks_for_first_name_and_phone_without_handing_over(): void
    {
        $this->claude->shouldReceive('askWithUsage')
            ->withArgs(fn (string $system, array $content, array $schema) => str_contains($content[0]['text'], 'Renvoi des billets par code SMS possible : oui')
                && in_array('ticket_resend', $schema['properties']['intent']['enum'], true))
            ->andReturn($this->resendAnswer(null, null, 'Tu me donnes ton prénom et le numéro utilisé pour la commande ?'));

        $this->say($this->openChat(), "J'ai pas reçu mon billet")
            ->assertOk()
            ->assertJsonPath('data.messages.1.content', 'Tu me donnes ton prénom et le numéro utilisé pour la commande ?')
            ->assertJsonPath('data.ask_contact', false);

        $this->assertFalse((bool) DB::table('live_chat_sessions')->value('needs_follow_up'));
    }

    public function test_matching_rules(): void
    {
        $this->assertSame('+262639123456', LiveChatTicketResendService::normalizePhone('0639 12 34 56'));
        $this->assertSame('+262639123456', LiveChatTicketResendService::normalizePhone('+262 639 12-34-56'));
        $this->assertSame('+262639123456', LiveChatTicketResendService::normalizePhone('639123456'));
        $this->assertSame('+33612345678', LiveChatTicketResendService::normalizePhone('06.12.34.56.78'));
        $this->assertNull(LiveChatTicketResendService::normalizePhone('12'));

        $this->assertTrue(LiveChatTicketResendService::firstNameMatches('elodie', ['Élodie']));
        $this->assertTrue(LiveChatTicketResendService::firstNameMatches(' ÉLODIE ', ['elodie']));
        $this->assertTrue(LiveChatTicketResendService::firstNameMatches('Jean Pierre', ['Jean-Pierre']));
        $this->assertTrue(LiveChatTicketResendService::firstNameMatches('Awa', ['Awa Fatima']));
        $this->assertFalse(LiveChatTicketResendService::firstNameMatches('Elo', ['Élodie']));
        $this->assertFalse(LiveChatTicketResendService::firstNameMatches('', ['Élodie']));

        $this->assertTrue(LiveChatTicketResendService::isResendable('COMPLETED', 'PAYMENT_RECEIVED', 'a@b.fr'));
        $this->assertFalse(LiveChatTicketResendService::isResendable('COMPLETED', 'AWAITING_OFFLINE_PAYMENT', 'a@b.fr'));
        $this->assertFalse(LiveChatTicketResendService::isResendable('COMPLETED', 'PAYMENT_RECEIVED', KioskSentinelEmail::forBoxOfficeSale(3)));
        $this->assertFalse(LiveChatTicketResendService::isResendable('COMPLETED', 'PAYMENT_RECEIVED', null));
        $this->assertFalse(LiveChatTicketResendService::isResendable('CANCELLED', 'PAYMENT_RECEIVED', 'a@b.fr'));

        $this->assertSame('j***@g***.com', LiveChatTicketResendService::maskEmail('Jean.Dupont@gmail.com'));
        $this->assertSame('a***@o***.fr', LiveChatTicketResendService::maskEmail('awa@orange.fr'));

        $this->assertSame('123456', LiveChatTicketResendService::extractCode('123 456'));
        $this->assertSame('123456', LiveChatTicketResendService::extractCode('Le code c\'est 123456'));
        $this->assertNull(LiveChatTicketResendService::extractCode('0639 12 34 56'));
        $this->assertNull(LiveChatTicketResendService::extractCode('12345'));
        $this->assertNull(LiveChatTicketResendService::extractCode('123456 et 654321'));
    }

    private function sentCode(): string
    {
        $this->assertNotEmpty($this->sentSms);
        $this->assertSame(1, preg_match('/\b(\d{6})\b/', end($this->sentSms)->content, $match));

        return $match[1];
    }

    private function resendAnswer(?string $firstName, ?string $phone, string $message = 'Je regarde ça.'): ClaudeStructuredAnswerDTO
    {
        return new ClaudeStructuredAnswerDTO(
            data: [
                'messages' => [$message],
                'needs_follow_up' => false,
                'ask_contact' => false,
                'reason' => null,
                'intent' => 'ticket_resend',
                'question_for_organizer' => null,
                'resend_first_name' => $firstName,
                'resend_phone' => $phone,
            ],
            model: 'claude-haiku-5-5',
            inputTokens: 1200,
            outputTokens: 80,
        );
    }

    private function order(
        string $firstName,
        string $phone,
        string $email = self::BUYER_EMAIL,
        string $status = 'COMPLETED',
        string $paymentStatus = 'PAYMENT_RECEIVED',
        string $provider = 'STRIPE',
        bool $manual = false,
        ?int $eventId = null,
    ): int {
        $eventId ??= $this->event->id;
        $orderId = DB::table('orders')->insertGetId([
            'short_id' => 'o_'.Str::random(13),
            'public_id' => 'O-'.strtoupper(Str::random(7)),
            'event_id' => $eventId,
            'total_before_additions' => 15,
            'total_gross' => 15,
            'currency' => 'EUR',
            'first_name' => $firstName,
            'last_name' => 'Martin',
            'email' => $email,
            'status' => $status,
            'payment_status' => $paymentStatus,
            'payment_provider' => $provider,
            'is_manually_created' => $manual,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $questionId = DB::table('questions')->insertGetId([
            'event_id' => $eventId,
            'title' => 'Téléphone',
            'type' => 'PHONE',
            'belongs_to' => 'ORDER',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('question_answers')->insert([
            'question_id' => $questionId,
            'order_id' => $orderId,
            'answer' => json_encode($phone),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $orderId;
    }

    private function settings(): void
    {
        DB::table('organizer_sms_settings')->insert([
            'account_id' => DB::table('organizers')->where('id', $this->organizerId)->value('account_id'),
            'organizer_id' => $this->organizerId,
            'webhook_token' => Str::random(48),
            'webhook_signing_key' => encrypt(Str::random(32)),
            'api_key' => Crypt::encryptString('test-api-key'),
            'from_number' => '+262639000001',
            'live_chat_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function openChat(?int $eventId = null): string
    {
        return (string) $this->postJson("/public/organizers/{$this->organizerId}/live-chat/sessions", ['event_id' => $eventId])
            ->assertCreated()
            ->json('data.session_id');
    }

    private function say(string $sessionId, string $message): TestResponse
    {
        return $this->json('POST', '/public/live-chat/messages', ['message' => $message], ['X-Chat-Session' => $sessionId]);
    }
}
