<?php

namespace Tests\Unit\Services\Domain\Sms;

use HiEvents\DomainObjects\Enums\SmsMessageAuthorType;
use HiEvents\Exceptions\SmsRecipientOptedOutException;
use HiEvents\Mail\Sms\PlatformSupervisionAlertMail;
use HiEvents\Models\OrganizerSmsSetting;
use HiEvents\Models\SmsMessage;
use HiEvents\Services\Application\Handlers\Sms\DTO\HttpSmsWebhookDTO;
use HiEvents\Services\Application\Handlers\Sms\HandleHttpSmsWebhookHandler;
use HiEvents\Services\Domain\Sms\DTO\SendSmsDTO;
use HiEvents\Services\Domain\Sms\HttpSmsWebhookSignatureVerifier;
use HiEvents\Services\Domain\Sms\Reminder\SmsReminderDispatchService;
use HiEvents\Services\Domain\Sms\SmsFileSyncService;
use HiEvents\Services\Domain\Sms\SmsSendService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

/**
 * PICHA Ticket plugged on the central SMS queue: urgent SMS at once, reminders as one « campagne » list,
 * statuses linked through id_sms_picha and the queue request_id « file-{id} ».
 */
class SmsFileFlowTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    private const FILE_URL = 'http://file.picha.test';

    private const FILE_KEY = 'secret-file-key-123';

    private const SMS_API = 'https://sms.picha.test/v1';

    private const LINE = '+262639000000';

    private const CUSTOMER = '+262639123456';

    private int $organizerId;

    private int $accountId;

    private int $eventId;

    private OrganizerSmsSetting $line;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::now('UTC')->startOfMinute());
        DB::table('organizer_sms_settings')->update(['api_key' => null, 'shared_line_settings_id' => null]);
        config([
            'services.sms_file.enabled' => true,
            'services.sms_file.url' => self::FILE_URL,
            'services.sms_file.key' => self::FILE_KEY,
            'services.httpsms.base_url' => self::SMS_API,
            'services.platform_supervisor.phones' => '',
            'services.sms_watchdog.alert_email' => 'veille@picha.test',
        ]);

        [$event] = $this->createEventWithProduct();
        $this->organizerId = $event->organizer_id;
        $this->accountId = $event->account_id;
        $this->eventId = $event->id;

        $this->line = OrganizerSmsSetting::create([
            'account_id' => $this->accountId,
            'organizer_id' => $this->organizerId,
            'webhook_token' => Str::random(40),
            'webhook_signing_key' => 'key',
            'api_key' => 'line-api-key',
            'from_number' => self::LINE,
        ]);

        $this->mock(HttpSmsWebhookSignatureVerifier::class)->shouldReceive('verify');
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_urgent_sms_goes_through_the_queue_and_keeps_the_sms_picha_id(): void
    {
        Http::fake([
            self::FILE_URL.'/v1/sms' => Http::response(['id' => 501, 'etat' => 'soumis', 'id_sms_picha' => 'picha-abc', 'ref' => 'x'], 201),
            self::SMS_API.'/*' => Http::response(['data' => ['id' => 'direct']], 200),
        ]);

        $message = app(SmsSendService::class)->send(new SendSmsDTO(organizerId: $this->organizerId, phone: '0639123456', content: 'Votre code : 1234'));

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->url() === self::FILE_URL.'/v1/sms'
            && $request->hasHeader('x-file-key', self::FILE_KEY)
            && $request['priorite'] === 'urgent'
            && $request['organisateur'] === $this->organizerId
            && $request['from'] === self::LINE
            && $request['to'] === self::CUSTOMER
            && $request['texte'] === 'Votre code : 1234'
            && $request['ref'] === 'msg:'.$message->getId());

        $stored = SmsMessage::find($message->getId());
        $this->assertSame('picha-abc', $stored->provider_message_id);
        $this->assertSame(501, (int) $stored->queue_message_id);
        $this->assertSame('PENDING', $stored->status);
        $this->assertSame('picha-sms-'.$message->getId(), $stored->request_id);
    }

    public function test_urgent_sms_falls_back_to_sms_picha_when_the_queue_is_down_and_never_logs_the_key(): void
    {
        $logged = [];
        Log::listen(function ($event) use (&$logged) {
            $logged[] = $event->message.json_encode($event->context);
        });

        Http::fake([
            self::FILE_URL.'/*' => Http::failedConnection('Connection refused'),
            self::SMS_API.'/messages/send' => Http::response(['data' => ['id' => 'direct-1']], 200),
        ]);

        $message = app(SmsSendService::class)->send(new SendSmsDTO(organizerId: $this->organizerId, phone: self::CUSTOMER, content: 'Bonjour'));

        Http::assertSent(fn (Request $request) => $request->url() === self::SMS_API.'/messages/send'
            && $request['request_id'] === 'picha-sms-'.$message->getId()
            && $request->hasHeader('x-api-key', 'line-api-key'));
        $this->assertSame('direct-1', SmsMessage::find($message->getId())->provider_message_id);
        $this->assertNull(SmsMessage::find($message->getId())->queue_message_id);
        $this->assertNotEmpty($logged);
        foreach ($logged as $line) {
            $this->assertStringNotContainsString(self::FILE_KEY, $line);
        }
    }

    public function test_urgent_sms_refused_by_the_queue_falls_back_to_sms_picha(): void
    {
        Http::fake([
            self::FILE_URL.'/*' => Http::response(['erreur' => 'ligne inconnue : +262639000000'], 422),
            self::SMS_API.'/messages/send' => Http::response(['data' => ['id' => 'direct-2']], 200),
        ]);

        $message = app(SmsSendService::class)->send(new SendSmsDTO(organizerId: $this->organizerId, phone: self::CUSTOMER, content: 'Bonjour'));

        $this->assertSame('direct-2', SmsMessage::find($message->getId())->provider_message_id);
    }

    public function test_disabled_queue_keeps_the_direct_call(): void
    {
        config(['services.sms_file.enabled' => false]);
        Http::fake([
            self::FILE_URL.'/*' => Http::response([], 500),
            self::SMS_API.'/messages/send' => Http::response(['data' => ['id' => 'direct-3']], 200),
        ]);

        app(SmsSendService::class)->send(new SendSmsDTO(organizerId: $this->organizerId, phone: self::CUSTOMER, content: 'Bonjour'));

        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request) => str_starts_with($request->url(), self::FILE_URL));
    }

    public function test_stop_is_still_respected_before_the_queue(): void
    {
        Http::fake();
        DB::table('sms_opt_outs')->insert([
            'organizer_id' => $this->organizerId,
            'phone' => self::CUSTOMER,
            'source' => 'KEYWORD',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            app(SmsSendService::class)->send(new SendSmsDTO(organizerId: $this->organizerId, phone: self::CUSTOMER, content: 'Bonjour'));
            $this->fail('An opted out number must not be sent to');
        } catch (SmsRecipientOptedOutException) {
            Http::assertNothingSent();
        }
    }

    public function test_reminder_is_handed_to_the_queue_in_one_campaign_list_then_linked_by_the_webhook(): void
    {
        $campaignId = $this->reminder();
        [$first, $second] = [$this->recipient($campaignId, self::CUSTOMER), $this->recipient($campaignId, '+262639222333')];

        Http::fake([
            self::FILE_URL.'/v1/sms' => fn (Request $request) => Http::response(array_map(fn (array $sms, int $i) => [
                'id' => 900 + $i,
                'etat' => 'en_file',
                'id_sms_picha' => null,
                'ref' => $sms['ref'],
            ], $request->data(), array_keys($request->data())), 200),
        ]);

        $result = app(SmsReminderDispatchService::class)->dispatchDue();

        $this->assertSame(2, $result->sent);
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) use ($campaignId, $first, $second) {
            $items = $request->data();

            return count($items) === 2
                && $items[0]['priorite'] === 'campagne'
                && $items[0]['campagne'] === 'rappel-'.$campaignId
                && $items[0]['ref'] === $campaignId.':'.$first
                && $items[1]['ref'] === $campaignId.':'.$second
                && $items[0]['from'] === self::LINE;
        });

        $message = SmsMessage::query()->where('queue_message_id', 900)->sole();
        $this->assertSame('QUEUED', $message->status);
        $this->assertSame(SmsMessageAuthorType::CAMPAIGN->name, $message->author_type);
        $this->assertNull($message->provider_message_id);
        $recipient = DB::table('sms_campaign_recipients')->find($first);
        $this->assertSame('SCHEDULED', $recipient->status);
        $this->assertSame(900, (int) $recipient->queue_message_id);
        $this->assertSame('SENT', DB::table('sms_campaigns')->find($campaignId)->status);

        $this->webhook('message.phone.sent', 'picha-777', self::CUSTOMER, 'file-900');

        $message->refresh();
        $this->assertSame('SENT', $message->status);
        $this->assertSame('picha-777', $message->provider_message_id);
        $recipient = DB::table('sms_campaign_recipients')->find($first);
        $this->assertSame('SENT', $recipient->status);
        $this->assertSame('picha-777', $recipient->provider_message_id);
        $this->assertSame(1, SmsMessage::query()->where('sms_conversation_id', $message->sms_conversation_id)->count());

        $this->webhook('message.phone.delivered', 'picha-777', self::CUSTOMER, 'file-900');
        $this->assertSame('DELIVERED', $message->refresh()->status);
        $this->assertSame('DELIVERED', DB::table('sms_campaign_recipients')->find($first)->status);
    }

    public function test_reminder_is_not_sent_directly_when_the_queue_is_down_and_waits_for_the_next_run(): void
    {
        $campaignId = $this->reminder();
        $recipientId = $this->recipient($campaignId, self::CUSTOMER);

        Http::fake([
            self::FILE_URL.'/*' => Http::failedConnection('Connection refused'),
            self::SMS_API.'/*' => Http::response(['data' => ['id' => 'direct']], 200),
        ]);

        $result = app(SmsReminderDispatchService::class)->dispatchDue();

        $this->assertSame(0, $result->sent);
        Http::assertNotSent(fn (Request $request) => str_starts_with($request->url(), self::SMS_API));
        $recipient = DB::table('sms_campaign_recipients')->find($recipientId);
        $this->assertSame('PENDING', $recipient->status);
        $this->assertNotNull($recipient->failure_reason);
        $this->assertSame('PROCESSING', DB::table('sms_campaigns')->find($campaignId)->status);
        $this->assertSame(0, SmsMessage::query()->where('organizer_id', $this->organizerId)->count());
        Mail::assertQueued(PlatformSupervisionAlertMail::class, 1);

        app(SmsReminderDispatchService::class)->dispatchDue();
        Mail::assertQueued(PlatformSupervisionAlertMail::class, 1);
    }

    public function test_sync_links_a_queued_sms_and_moves_its_status_forward(): void
    {
        $campaignId = $this->reminder();
        $recipientId = $this->recipient($campaignId, self::CUSTOMER, status: 'SCHEDULED', queueId: 950);
        $conversationId = DB::table('sms_conversations')->insertGetId([
            'account_id' => $this->accountId,
            'organizer_id' => $this->organizerId,
            'phone' => self::CUSTOMER,
            'status' => 'OPEN',
            'line_settings_id' => $this->line->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $queued = SmsMessage::create([
            'sms_conversation_id' => $conversationId,
            'organizer_id' => $this->organizerId,
            'direction' => 'OUTBOUND',
            'author_type' => 'CAMPAIGN',
            'content' => 'Rappel',
            'status' => 'QUEUED',
            'line_settings_id' => $this->line->id,
            'queue_message_id' => 950,
            'queue_checked_at' => Carbon::now('UTC')->subMinutes(10),
        ]);
        $recent = SmsMessage::create([
            'sms_conversation_id' => $conversationId,
            'organizer_id' => $this->organizerId,
            'direction' => 'OUTBOUND',
            'author_type' => 'CAMPAIGN',
            'content' => 'Rappel 2',
            'status' => 'QUEUED',
            'line_settings_id' => $this->line->id,
            'queue_message_id' => 951,
            'queue_checked_at' => Carbon::now('UTC')->subMinute(),
        ]);

        Http::fake([
            self::FILE_URL.'/v1/sms/950' => Http::response(['id' => 950, 'etat' => 'distribue', 'id_sms_picha' => 'picha-950', 'raison' => null], 200),
            self::FILE_URL.'/*' => Http::response(['erreur' => 'inattendu'], 500),
        ]);

        app(SmsFileSyncService::class)->sync();

        Http::assertSentCount(1);
        $queued->refresh();
        $this->assertSame('DELIVERED', $queued->status);
        $this->assertSame('picha-950', $queued->provider_message_id);
        $this->assertSame('QUEUED', $recent->refresh()->status);
        $recipient = DB::table('sms_campaign_recipients')->find($recipientId);
        $this->assertSame('DELIVERED', $recipient->status);
        $this->assertSame('picha-950', $recipient->provider_message_id);
    }

    private function reminder(): int
    {
        return DB::table('sms_campaigns')->insertGetId([
            'account_id' => $this->accountId,
            'organizer_id' => $this->organizerId,
            'event_id' => $this->eventId,
            'name' => 'Rappel J-1',
            'message' => 'Rappel : on vous attend demain !',
            'type' => 'REMINDER',
            'status' => 'PROCESSING',
            'scheduled_at' => Carbon::now('UTC')->subMinutes(5),
            'started_at' => Carbon::now('UTC')->subMinutes(5),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function recipient(int $campaignId, string $phone, string $status = 'PENDING', ?int $queueId = null): int
    {
        return DB::table('sms_campaign_recipients')->insertGetId([
            'sms_campaign_id' => $campaignId,
            'phone' => $phone,
            'status' => $status,
            'queue_message_id' => $queueId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function webhook(string $type, string $providerId, string $contact, string $requestId): void
    {
        app(HandleHttpSmsWebhookHandler::class)->handle(new HttpSmsWebhookDTO(
            webhookToken: $this->line->webhook_token,
            bearerToken: 'signed',
            eventType: $type,
            payload: json_encode(['data' => [
                'id' => $providerId,
                'contact' => $contact,
                'owner' => self::LINE,
                'content' => 'Rappel',
                'request_id' => $requestId,
                'timestamp' => Carbon::now('UTC')->toIso8601String(),
            ]]),
        ));
    }
}
