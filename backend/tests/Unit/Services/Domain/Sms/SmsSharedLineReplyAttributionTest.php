<?php

namespace Tests\Unit\Services\Domain\Sms;

use HiEvents\DomainObjects\OrganizerSmsSettingDomainObject;
use HiEvents\Models\Organizer;
use HiEvents\Models\OrganizerSmsSetting;
use HiEvents\Models\SmsConversation;
use HiEvents\Models\SmsMessage;
use HiEvents\Models\SmsUnattributedMessage;
use HiEvents\Services\Application\Handlers\Sms\DTO\HttpSmsWebhookDTO;
use HiEvents\Services\Application\Handlers\Sms\HandleHttpSmsWebhookHandler;
use HiEvents\Services\Domain\Sms\DTO\SendSmsDTO;
use HiEvents\Services\Domain\Sms\HttpSmsWebhookSignatureVerifier;
use HiEvents\Services\Domain\Sms\SmsAlertService;
use HiEvents\Services\Domain\Sms\SmsAssistantService;
use HiEvents\Services\Domain\Sms\SmsSendService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

/**
 * « Ligne PICHA » shared by Innocent (owner), Kultur'm and Raid: replies of people who got an SMS through the
 * central queue must reach the organizer who wrote to them.
 */
class SmsSharedLineReplyAttributionTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    private const NOW = '2026-10-29 12:00:00';

    private const FILE_URL = 'http://file.picha.test';

    private const LINE = '+262639000000';

    private const CONTACT = '+262639651126';

    private int $innocentId;

    private int $kulturmId;

    private int $raidId;

    private OrganizerSmsSetting $line;

    private MockInterface $alerts;

    private MockInterface $assistant;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC'));
        DB::table('organizer_sms_settings')->update(['api_key' => null, 'shared_line_settings_id' => null]);
        config([
            'services.sms_file.enabled' => true,
            'services.sms_file.url' => self::FILE_URL,
            'services.sms_file.key' => 'file-key',
            'services.platform_supervisor.phones' => '',
        ]);

        [$event] = $this->createEventWithProduct();
        $this->innocentId = $event->organizer_id;
        $this->kulturmId = $this->organizer($event->account_id, 'Kulturm');
        $this->raidId = $this->organizer($event->account_id, 'Raid');

        $this->line = $this->settings($this->innocentId, 'uk_line', self::LINE);
        $this->settings($this->kulturmId, null, null, $this->line->id);
        $this->settings($this->raidId, null, null, $this->line->id);

        $this->mock(HttpSmsWebhookSignatureVerifier::class)->shouldReceive('verify');
        $this->alerts = $this->mock(SmsAlertService::class, function (MockInterface $alerts) {
            $alerts->shouldReceive('markPhoneActive')->andReturnUsing(fn (OrganizerSmsSettingDomainObject $settings) => $settings)->byDefault();
            $alerts->shouldReceive('notifyInbound')->byDefault();
        });
        $this->assistant = $this->mock(SmsAssistantService::class, function (MockInterface $assistant) {
            $assistant->shouldReceive('queueDraftIfEligible')->byDefault();
        });
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_urgent_sms_through_the_queue_creates_the_conversation_of_its_organizer_on_the_line(): void
    {
        Http::fake([self::FILE_URL.'/v1/sms' => Http::response(['id' => 700, 'etat' => 'soumis', 'id_sms_picha' => 'picha-700'], 201)]);

        $message = app(SmsSendService::class)->send(new SendSmsDTO(organizerId: $this->kulturmId, phone: self::CONTACT, content: 'Votre billet'));

        $conversation = SmsConversation::query()->where('organizer_id', $this->kulturmId)->where('phone', self::CONTACT)->sole();
        $this->assertSame($this->line->id, $conversation->line_settings_id);
        $this->assertSame($conversation->id, $message->getSmsConversationId());
        $this->assertSame(700, (int) SmsMessage::find($message->getId())->queue_message_id);

        $this->receive('in-1', 'Merci !');

        $this->assertSame(1, $this->inbound($this->kulturmId)->count());
        $this->assertSame(0, SmsUnattributedMessage::query()->count());
    }

    public function test_queue_sms_unknown_to_picha_is_recorded_for_the_organizer_the_queue_sent_it_for_and_its_reply_follows(): void
    {
        Http::fake([self::FILE_URL.'/v1/sms/812' => Http::response([
            'id' => 812, 'organisateur' => $this->kulturmId, 'etat' => 'soumis', 'id_sms_picha' => 'picha-812',
        ], 200)]);

        $this->providerStatus('message.phone.sent', 'picha-812', 'file-812');
        $this->providerStatus('message.phone.delivered', 'picha-812', 'file-812');

        Http::assertSentCount(1);
        $outbound = SmsMessage::query()->where('provider_message_id', 'picha-812')->sole();
        $this->assertSame($this->kulturmId, $outbound->organizer_id);
        $this->assertSame(812, (int) $outbound->queue_message_id);
        $this->assertSame('DELIVERED', $outbound->status);
        $this->assertSame($this->line->id, $outbound->line_settings_id);
        $this->assertSame(1, SmsMessage::query()->where('queue_message_id', 812)->count());

        $this->receive('in-2', 'Il reste des places ?');

        $this->assertSame(1, $this->inbound($this->kulturmId)->count());
        $this->assertSame(0, SmsUnattributedMessage::query()->count());
    }

    public function test_reply_goes_to_the_organizer_who_wrote_last_when_two_did(): void
    {
        $this->outbound($this->kulturmId, daysAgo: 5);
        $this->outbound($this->raidId, daysAgo: 1);

        $this->receive('in-3', 'Oui je viens');

        $this->assertSame(1, $this->inbound($this->raidId)->count());
        $this->assertSame(0, $this->inbound($this->kulturmId)->count());
    }

    public function test_reply_to_a_campaign_known_only_by_its_recipients_goes_to_the_campaign_organizer(): void
    {
        $this->outbound($this->kulturmId, daysAgo: 6);
        $this->campaignRecipient($this->raidId, sentDaysAgo: 2);

        $this->receive('in-4', 'Ok');

        $this->assertSame(1, $this->inbound($this->raidId)->count());
    }

    public function test_reply_stays_unattributed_when_nobody_wrote_within_the_window(): void
    {
        $this->outbound($this->kulturmId, daysAgo: 45);

        $this->receive('in-5', 'Bonjour');

        $this->assertSame(1, SmsUnattributedMessage::query()->where('provider_message_id', 'in-5')->whereNull('assigned_organizer_id')->count());
        $this->assertSame(0, SmsMessage::query()->where('provider_message_id', 'in-5')->count());
    }

    public function test_backfill_dry_run_writes_nothing_then_real_run_attributes_once_and_keeps_the_dates(): void
    {
        $this->outbound($this->kulturmId, daysAgo: 3);
        $this->outbound($this->raidId, daysAgo: 2);
        $receivedAt = Carbon::now('UTC')->subDay()->toDateTimeString();
        $toRaid = $this->unattributed('old-1', self::CONTACT, $receivedAt);
        $nobody = $this->unattributed('old-2', '+262693514915', $receivedAt);
        $tooLate = $this->unattributed('old-3', self::CONTACT, Carbon::now('UTC')->subDays(40)->toDateTimeString());

        $this->assertSame(0, Artisan::call('sms:attribute-unattributed', ['--dry-run' => true]));
        $this->assertStringContainsString('Would be attributed: 1, left unattributed: 2', Artisan::output());
        $this->assertSame(0, SmsMessage::query()->where('provider_message_id', 'old-1')->count());
        $this->assertNull($toRaid->fresh()->assigned_at);

        Event::fake();
        Queue::fake();
        Bus::fake();
        $this->alerts->shouldNotReceive('notifyInbound');
        $this->assistant->shouldNotReceive('queueDraftIfEligible');

        $this->assertSame(0, Artisan::call('sms:attribute-unattributed', ['-v' => true]));
        $output = Artisan::output();

        $created = SmsMessage::query()->where('provider_message_id', 'old-1')->sole();
        $this->assertSame($this->raidId, $created->organizer_id);
        $this->assertSame('INBOUND', $created->direction);
        $this->assertSame($this->line->id, $created->line_settings_id);
        $this->assertSame($toRaid->created_at->toDateTimeString(), $created->created_at->toDateTimeString());
        $this->assertSame($receivedAt, Carbon::parse($created->provider_timestamp)->toDateTimeString());
        $this->assertSame($this->raidId, $toRaid->fresh()->assigned_organizer_id);
        $this->assertSame($created->id, $toRaid->fresh()->sms_message_id);
        $this->assertNull($nobody->fresh()->assigned_at);
        $this->assertNull($tooLate->fresh()->assigned_at);
        $this->assertStringContainsString(sprintf('Organizer %d: highest sms_messages ID created = %d', $this->raidId, $created->id), $output);
        $this->assertStringContainsString('sms_messages IDs created: '.$created->id, $output);

        $this->assertSame([], array_values(array_filter(
            array_keys(Event::dispatchedEvents()),
            fn (string $event) => ! str_starts_with($event, 'eloquent.'),
        )));
        Queue::assertNothingPushed();
        Bus::assertNothingDispatched();
        Mail::assertNothingQueued();
        Mail::assertNothingSent();

        $this->assertSame(0, Artisan::call('sms:attribute-unattributed'));
        $this->assertStringContainsString('Attributed: 0, left unattributed: 2', Artisan::output());
        $this->assertSame(1, SmsMessage::query()->where('provider_message_id', 'old-1')->count());
    }

    public function test_backfill_never_gives_an_sms_to_an_organizer_outside_the_line(): void
    {
        $outsiderId = $this->organizer(Organizer::find($this->innocentId)->account_id, 'Outsider');
        $this->settings($outsiderId, 'uk_other', '+262639999999');
        $conversation = SmsConversation::create([
            'account_id' => Organizer::find($outsiderId)->account_id,
            'organizer_id' => $outsiderId,
            'phone' => self::CONTACT,
            'status' => 'OPEN',
        ]);
        SmsMessage::create([
            'sms_conversation_id' => $conversation->id,
            'organizer_id' => $outsiderId,
            'direction' => 'OUTBOUND',
            'author_type' => 'HUMAN',
            'content' => 'Bonjour',
            'status' => 'SENT',
        ]);
        $this->unattributed('old-4', self::CONTACT, Carbon::now('UTC')->subHour()->toDateTimeString());

        Artisan::call('sms:attribute-unattributed');

        $this->assertSame(0, SmsMessage::query()->where('provider_message_id', 'old-4')->count());
    }

    public function test_brayan_team_reply_stays_in_his_team_conversation_even_without_his_team_contact_row(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-28 20:43:43', 'UTC'));
        $this->outbound($this->raidId, daysAgo: 1, phone: self::CONTACT);
        $conversation = $this->teamConversation('2026-10-28 20:40:17');

        $this->assertNull(DB::table('sms_messages')
            ->join('sms_conversations', 'sms_conversations.id', '=', 'sms_messages.sms_conversation_id')
            ->where('sms_conversations.id', $conversation->id)
            ->where('sms_conversations.is_team', false)
            ->first(), 'The former query, with is_team = false, could not see Brayan conversation');

        $this->receive('in-brayan', 'Scan ok, 120 entrées');

        $reply = SmsMessage::query()->where('provider_message_id', 'in-brayan')->sole();
        $this->assertSame($this->innocentId, $reply->organizer_id);
        $this->assertSame($conversation->id, $reply->sms_conversation_id);
        $this->assertTrue((bool) $conversation->fresh()->is_team);
        $this->assertSame(0, SmsUnattributedMessage::query()->count());
        $this->assertSame(1, SmsConversation::query()->where('phone', self::CONTACT)->where('organizer_id', $this->innocentId)->count());
        $this->assistant->shouldNotHaveReceived('queueDraftIfEligible');
    }

    public function test_team_member_saved_with_plus_33_is_still_recognised(): void
    {
        $conversation = $this->teamConversation(Carbon::now('UTC')->subMinutes(3)->toDateTimeString());
        DB::table('organizer_team_contacts')->insert([
            'organizer_id' => $this->innocentId, 'phone' => '+33639651126', 'name' => 'Brayan', 'role' => 'COPY',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->receive('in-team', 'Ok');

        $this->assertSame($conversation->id, SmsMessage::query()->where('provider_message_id', 'in-team')->sole()->sms_conversation_id);
    }

    public function test_backfill_attaches_a_team_reply_to_the_existing_team_conversation(): void
    {
        $conversation = $this->teamConversation(Carbon::now('UTC')->subHours(2)->toDateTimeString());
        $this->outbound($this->raidId, daysAgo: 0, phone: self::CONTACT);
        $this->unattributed('old-team', self::CONTACT, Carbon::now('UTC')->toDateTimeString());

        $this->assertSame(0, Artisan::call('sms:attribute-unattributed'));

        $created = SmsMessage::query()->where('provider_message_id', 'old-team')->sole();
        $this->assertSame($this->innocentId, $created->organizer_id);
        $this->assertSame($conversation->id, $created->sms_conversation_id);
        $this->assertSame(1, SmsConversation::query()->where('organizer_id', $this->innocentId)->where('phone', self::CONTACT)->count());
    }

    public function test_fix_phones_dry_run_only_shows_then_renames_or_merges_and_fixes_the_stop(): void
    {
        $accountId = Organizer::find($this->innocentId)->account_id;
        $wrong = SmsConversation::create([
            'account_id' => $accountId, 'organizer_id' => $this->innocentId, 'phone' => '+33693514915',
            'status' => 'OPEN', 'line_settings_id' => $this->line->id, 'unread_count' => 1,
            'last_message_at' => Carbon::now('UTC')->subMinute(), 'last_message_preview' => 'Merci',
        ]);
        $wrongMessage = SmsMessage::create([
            'sms_conversation_id' => $wrong->id, 'organizer_id' => $this->innocentId, 'direction' => 'OUTBOUND',
            'author_type' => 'HUMAN', 'content' => 'Ton billet', 'status' => 'DELIVERED', 'line_settings_id' => $this->line->id,
        ]);
        $right = SmsConversation::create([
            'account_id' => $accountId, 'organizer_id' => $this->innocentId, 'phone' => '+262693514915',
            'status' => 'OPEN', 'line_settings_id' => $this->line->id, 'unread_count' => 2,
            'last_message_at' => Carbon::now('UTC')->subHour(),
        ]);
        $alone = SmsConversation::create([
            'account_id' => Organizer::find($this->kulturmId)->account_id, 'organizer_id' => $this->kulturmId,
            'phone' => '+33639123456', 'status' => 'OPEN', 'line_settings_id' => $this->line->id,
        ]);
        $france = SmsConversation::create([
            'account_id' => $accountId, 'organizer_id' => $this->innocentId, 'phone' => '+33612345678', 'status' => 'OPEN',
        ]);
        DB::table('sms_opt_outs')->insert([
            'organizer_id' => $this->kulturmId, 'phone' => '+33639123456', 'source' => 'KEYWORD',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('organizer_team_contacts')->insert([
            'organizer_id' => $this->innocentId, 'phone' => '+33693514915', 'role' => 'COPY', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame(0, Artisan::call('sms:attribute-unattributed', ['--fix-phones' => true, '--dry-run' => true]));
        $this->assertStringContainsString('Numbers written with +33 instead of +262: 4.', Artisan::output());
        $this->assertSame('+33693514915', $wrong->fresh()->phone);
        $this->assertSame('+33639123456', $alone->fresh()->phone);

        $this->assertSame(0, Artisan::call('sms:attribute-unattributed', ['--fix-phones' => true]));

        $this->assertNull($wrong->fresh());
        $this->assertSame($right->id, $wrongMessage->fresh()->sms_conversation_id);
        $right->refresh();
        $this->assertSame(3, $right->unread_count);
        $this->assertSame('Merci', $right->last_message_preview);
        $this->assertSame('+262639123456', $alone->fresh()->phone);
        $this->assertSame('+33612345678', $france->fresh()->phone);
        $this->assertSame(1, DB::table('sms_opt_outs')->where('organizer_id', $this->kulturmId)->where('phone', '+262639123456')->count());
        $this->assertSame(0, DB::table('sms_opt_outs')->where('phone', '+33639123456')->count());
        $this->assertSame(1, DB::table('organizer_team_contacts')->where('organizer_id', $this->innocentId)->where('phone', '+262693514915')->count());

        Artisan::call('sms:attribute-unattributed', ['--fix-phones' => true]);
        $this->assertStringContainsString('Numbers written with +33 instead of +262: 0.', Artisan::output());
    }

    private function receive(string $messageId, string $content): void
    {
        app(HandleHttpSmsWebhookHandler::class)->handle(new HttpSmsWebhookDTO(
            webhookToken: $this->line->webhook_token,
            bearerToken: 'signed',
            eventType: HandleHttpSmsWebhookHandler::EVENT_RECEIVED,
            payload: json_encode(['data' => [
                'message_id' => $messageId,
                'contact' => self::CONTACT,
                'owner' => self::LINE,
                'content' => $content,
                'timestamp' => Carbon::now('UTC')->toIso8601String(),
            ]]),
        ));
    }

    private function providerStatus(string $type, string $providerId, string $requestId): void
    {
        app(HandleHttpSmsWebhookHandler::class)->handle(new HttpSmsWebhookDTO(
            webhookToken: $this->line->webhook_token,
            bearerToken: 'signed',
            eventType: $type,
            payload: json_encode(['data' => [
                'id' => $providerId,
                'contact' => self::CONTACT,
                'owner' => self::LINE,
                'content' => 'Halloween : derniers billets',
                'request_id' => $requestId,
                'timestamp' => Carbon::now('UTC')->subDays(2)->toIso8601String(),
            ]]),
        ));
    }

    private function inbound(int $organizerId)
    {
        return SmsMessage::query()->where('organizer_id', $organizerId)->where('direction', 'INBOUND');
    }

    private function teamConversation(string $sentAt): SmsConversation
    {
        $conversation = SmsConversation::create([
            'account_id' => Organizer::find($this->innocentId)->account_id,
            'organizer_id' => $this->innocentId,
            'phone' => self::CONTACT,
            'contact_name' => 'Brayan',
            'status' => 'OPEN',
            'is_team' => true,
            'line_settings_id' => $this->line->id,
            'last_message_at' => $sentAt,
        ]);
        SmsMessage::create([
            'sms_conversation_id' => $conversation->id,
            'organizer_id' => $this->innocentId,
            'direction' => 'OUTBOUND',
            'author_type' => 'HUMAN',
            'content' => 'Tu peux ouvrir les portes',
            'status' => 'DELIVERED',
            'line_settings_id' => $this->line->id,
            'provider_timestamp' => $sentAt,
        ]);

        return $conversation;
    }

    private function outbound(int $organizerId, int $daysAgo, string $phone = self::CONTACT): void
    {
        $conversation = SmsConversation::query()->firstOrCreate(
            ['organizer_id' => $organizerId, 'phone' => $phone],
            ['account_id' => Organizer::find($organizerId)->account_id, 'status' => 'OPEN', 'line_settings_id' => $this->line->id],
        );
        $at = Carbon::now('UTC')->subDays($daysAgo);

        SmsMessage::create([
            'sms_conversation_id' => $conversation->id,
            'organizer_id' => $organizerId,
            'direction' => 'OUTBOUND',
            'author_type' => 'CAMPAIGN',
            'content' => 'Rappel',
            'status' => 'SENT',
            'line_settings_id' => $this->line->id,
            'provider_timestamp' => $at,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    private function campaignRecipient(int $organizerId, int $sentDaysAgo): void
    {
        $campaignId = DB::table('sms_campaigns')->insertGetId([
            'account_id' => Organizer::find($organizerId)->account_id,
            'organizer_id' => $organizerId,
            'name' => 'Relance',
            'message' => 'Relance',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('sms_campaign_recipients')->insert([
            'sms_campaign_id' => $campaignId,
            'phone' => self::CONTACT,
            'status' => 'DELIVERED',
            'sent_at' => Carbon::now('UTC')->subDays($sentDaysAgo),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function unattributed(string $providerId, string $phone, string $receivedAt): SmsUnattributedMessage
    {
        return SmsUnattributedMessage::create([
            'line_settings_id' => $this->line->id,
            'phone' => $phone,
            'content' => 'Réponse',
            'provider_message_id' => $providerId,
            'provider_timestamp' => $receivedAt,
            'opt_out_applied' => false,
            'created_at' => Carbon::parse($receivedAt)->addSeconds(3),
            'updated_at' => Carbon::parse($receivedAt)->addSeconds(3),
        ]);
    }

    private function organizer(int $accountId, string $name): int
    {
        return Organizer::create([
            'account_id' => $accountId,
            'name' => $name,
            'email' => Str::lower($name).'@example.test',
            'currency' => 'EUR',
            'timezone' => 'UTC',
        ])->id;
    }

    private function settings(int $organizerId, ?string $apiKey, ?string $fromNumber, ?int $sharedLineId = null): OrganizerSmsSetting
    {
        return OrganizerSmsSetting::create([
            'account_id' => Organizer::find($organizerId)->account_id,
            'organizer_id' => $organizerId,
            'webhook_token' => Str::random(40),
            'webhook_signing_key' => 'key',
            'api_key' => $apiKey,
            'from_number' => $fromNumber,
            'shared_line_settings_id' => $sharedLineId,
        ]);
    }
}
