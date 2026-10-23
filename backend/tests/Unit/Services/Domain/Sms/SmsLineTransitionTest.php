<?php

namespace Tests\Unit\Services\Domain\Sms;

use HiEvents\DomainObjects\Enums\SmsAutoReplySkipReason;
use HiEvents\DomainObjects\Enums\SmsMessageAuthorType;
use HiEvents\DomainObjects\OrganizerSmsSettingDomainObject;
use HiEvents\Models\Organizer;
use HiEvents\Models\OrganizerSmsSetting;
use HiEvents\Models\SmsConversation;
use HiEvents\Models\SmsLineFormerMember;
use HiEvents\Models\SmsMessage;
use HiEvents\Repository\Interfaces\OrganizerSmsSettingRepositoryInterface;
use HiEvents\Services\Application\Handlers\Sms\DTO\HttpSmsWebhookDTO;
use HiEvents\Services\Application\Handlers\Sms\HandleHttpSmsWebhookHandler;
use HiEvents\Services\Application\Handlers\Sms\SendSmsReplyHandler;
use HiEvents\Services\Domain\Sms\DTO\SendSmsDTO;
use HiEvents\Services\Domain\Sms\HttpSmsWebhookSignatureVerifier;
use HiEvents\Services\Domain\Sms\SmsAlertService;
use HiEvents\Services\Domain\Sms\SmsAssistantService;
use HiEvents\Services\Domain\Sms\SmsAutoReplyService;
use HiEvents\Services\Domain\Sms\SmsSendService;
use HiEvents\Services\Infrastructure\HttpSms\HttpSmsClient;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

/**
 * Organizer "Innocent" owns the line, organizer "Kultur'm" was attached to it and gets its own line.
 */
class SmsLineTransitionTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    private const NOW = '2026-10-23 12:00:00';

    private const OLD_NUMBER = '+262639000000';

    private const NEW_NUMBER = '+262639111111';

    private const KULTURM_CONTACT = '+262639250001';

    private const INNOCENT_CONTACT = '+262639250002';

    private const UNKNOWN_CONTACT = '+262639250003';

    private int $innocentId;

    private int $kulturmId;

    private OrganizerSmsSetting $line;

    private OrganizerSmsSetting $kulturmSettings;

    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC'));
        DB::table('organizer_sms_settings')->update(['api_key' => null, 'shared_line_settings_id' => null]);

        [$event] = $this->createEventWithProduct();
        $this->innocentId = $event->organizer_id;
        $this->kulturmId = Organizer::create([
            'account_id' => $event->account_id,
            'name' => 'Kulturm',
            'email' => 'kulturm@example.test',
            'currency' => 'EUR',
            'timezone' => 'UTC',
        ])->id;

        $this->line = $this->settings($this->innocentId, 'uk_old', self::OLD_NUMBER);
        $this->kulturmSettings = $this->settings($this->kulturmId, null, null, $this->line->id);

        $kulturmConversation = $this->conversation($this->kulturmId, self::KULTURM_CONTACT);
        $this->message($kulturmConversation, 'OUTBOUND', 'Votre inscription est confirmée.', daysAgo: 3);

        $innocentConversation = $this->conversation($this->innocentId, self::INNOCENT_CONTACT);
        $this->message($innocentConversation, 'OUTBOUND', 'Ton billet est prêt !', daysAgo: 2);

        $this->mock(HttpSmsClient::class, function (MockInterface $client) {
            $client->shouldReceive('sendMessage')->andReturnUsing(function (string $apiKey, string $from, string $to) {
                $this->sent[] = compact('apiKey', 'from', 'to');

                return 'provider-'.count($this->sent);
            });
        });
        $this->mock(HttpSmsWebhookSignatureVerifier::class)->shouldReceive('verify');
        $this->mock(SmsAlertService::class, function (MockInterface $alerts) {
            $alerts->shouldReceive('markPhoneActive')->andReturnUsing(fn (OrganizerSmsSettingDomainObject $settings) => $settings);
            $alerts->shouldReceive('notifyInbound');
        });
        $this->mock(SmsAssistantService::class, function (MockInterface $assistant) {
            $assistant->shouldReceive('queueDraftIfEligible');
            $assistant->shouldReceive('isActiveFor')->andReturnTrue();
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        putenv('TEST_NEW_LINE_API_KEY');

        parent::tearDown();
    }

    public function test_detach_command_records_the_former_membership_and_sets_the_new_line(): void
    {
        $this->detach();

        $former = SmsLineFormerMember::query()->sole();
        $this->assertSame($this->line->id, $former->line_settings_id);
        $this->assertSame($this->kulturmId, $former->organizer_id);
        $this->assertSame(self::NOW, Carbon::parse($former->detached_at)->toDateTimeString());

        $settings = $this->kulturmSettings->fresh();
        $this->assertNull($settings->shared_line_settings_id);
        $this->assertSame('uk_new', $settings->api_key);
        $this->assertSame(self::NEW_NUMBER, $settings->from_number);
    }

    public function test_detach_command_refuses_an_organizer_without_shared_line(): void
    {
        $this->assertSame(1, Artisan::call('sms:detach-line', ['organizer' => $this->innocentId, '--no-interaction' => true]));
        $this->assertSame(0, SmsLineFormerMember::query()->count());
    }

    public function test_after_detach_a_kulturm_contact_answering_the_old_number_reaches_kulturm(): void
    {
        $this->detach();

        $this->receiveOnOldLine(self::KULTURM_CONTACT, 'Merci, à quelle heure ça commence ?');

        $message = SmsMessage::query()->where('provider_message_id', 'in-1')->sole();
        $this->assertSame($this->kulturmId, $message->organizer_id);
        $this->assertSame($this->line->id, $message->line_settings_id);
        $this->assertSame($this->line->id, SmsConversation::find($message->sms_conversation_id)->line_settings_id);
        $this->assertSame(0, SmsConversation::query()
            ->where('organizer_id', $this->innocentId)
            ->where('phone', self::KULTURM_CONTACT)
            ->count());
    }

    public function test_after_detach_an_innocent_contact_and_an_unknown_number_still_reach_innocent(): void
    {
        $this->detach();

        $this->receiveOnOldLine(self::INNOCENT_CONTACT, 'Il reste des places ?', 'in-1');
        $this->receiveOnOldLine(self::UNKNOWN_CONTACT, 'Bonjour', 'in-2');

        $this->assertSame($this->innocentId, SmsMessage::query()->where('provider_message_id', 'in-1')->sole()->organizer_id);
        $this->assertSame($this->innocentId, SmsMessage::query()->where('provider_message_id', 'in-2')->sole()->organizer_id);
    }

    public function test_innocent_writing_last_to_a_kulturm_contact_gets_its_answer(): void
    {
        $this->detach();
        $this->message($this->conversation($this->innocentId, self::KULTURM_CONTACT), 'OUTBOUND', 'Salut !', daysAgo: 1);

        $this->receiveOnOldLine(self::KULTURM_CONTACT, 'Salut');

        $this->assertSame($this->innocentId, SmsMessage::query()->where('provider_message_id', 'in-1')->sole()->organizer_id);
    }

    public function test_once_the_transition_is_over_the_old_line_is_innocent_only(): void
    {
        config(['services.httpsms.detached_line_transition_days' => 7]);
        $this->detach();
        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC')->addDays(8));

        $this->receiveOnOldLine(self::KULTURM_CONTACT, 'Toujours là ?');

        $this->assertSame($this->innocentId, SmsMessage::query()->where('provider_message_id', 'in-1')->sole()->organizer_id);
    }

    public function test_kulturm_reply_leaves_from_the_old_number_the_contact_wrote_to(): void
    {
        $this->detach();
        $this->receiveOnOldLine(self::KULTURM_CONTACT, 'Ça commence à quelle heure ?');
        $conversation = $this->kulturmConversation();

        $message = app(SendSmsReplyHandler::class)->handle($this->kulturmId, $conversation->id, 'À 19h.', null);

        $this->assertSame([['apiKey' => 'uk_old', 'from' => self::OLD_NUMBER, 'to' => self::KULTURM_CONTACT]], $this->sent);
        $this->assertSame($this->line->id, $message->getLineSettingsId());
    }

    public function test_kulturm_reply_leaves_from_its_new_line_once_the_transition_is_over(): void
    {
        config(['services.httpsms.detached_line_transition_days' => 7]);
        $this->detach();
        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC')->addDays(8));

        app(SendSmsReplyHandler::class)->handle($this->kulturmId, $this->kulturmConversation()->id, 'Bonjour', null);

        $this->assertSame(self::NEW_NUMBER, $this->sent[0]['from']);
        $this->assertSame('uk_new', $this->sent[0]['apiKey']);
    }

    public function test_new_kulturm_sms_leave_from_its_new_line(): void
    {
        $this->detach();

        $sendService = app(SmsSendService::class);
        $sendService->send(new SendSmsDTO($this->kulturmId, '0639250009', 'Inscription confirmée', SmsMessageAuthorType::CAMPAIGN));
        $sendService->send(new SendSmsDTO($this->kulturmId, self::KULTURM_CONTACT, 'Rappel : demain 19h', SmsMessageAuthorType::CAMPAIGN));

        $this->assertSame(
            [
                ['apiKey' => 'uk_new', 'from' => self::NEW_NUMBER, 'to' => '+262639250009'],
                ['apiKey' => 'uk_new', 'from' => self::NEW_NUMBER, 'to' => self::KULTURM_CONTACT],
            ],
            $this->sent,
        );
        $this->assertSame($this->kulturmSettings->id, $this->kulturmConversation()->line_settings_id);
    }

    public function test_no_automatic_reply_when_the_other_line_already_wrote_to_the_number(): void
    {
        $this->detach();
        $this->kulturmSettings->update(['assistant_enabled' => true, 'assistant_auto_send' => true]);
        $this->line->update(['assistant_enabled' => true, 'assistant_auto_send' => true]);

        $this->receiveOnOldLine(self::KULTURM_CONTACT, 'Il y a un parking ?', 'in-1');
        $kulturmInbound = SmsMessage::query()->where('provider_message_id', 'in-1')->sole();
        $this->message($this->conversation($this->innocentId, self::KULTURM_CONTACT), 'OUTBOUND', 'Oui, devant la salle.', daysAgo: 0);

        $this->receiveOnOldLine(self::INNOCENT_CONTACT, 'Je peux payer sur place ?', 'in-2');
        $innocentInbound = SmsMessage::query()->where('provider_message_id', 'in-2')->sole();
        app(SmsSendService::class)->send(new SendSmsDTO($this->kulturmId, self::INNOCENT_CONTACT, 'Bonjour de Kulturm'));

        $autoReply = app(SmsAutoReplyService::class);
        $this->assertSame(
            SmsAutoReplySkipReason::OUTBOUND_AFTER_MESSAGE,
            $autoReply->check($kulturmInbound->sms_conversation_id, $kulturmInbound->id, null, 'Je vérifie.')->skipReason,
        );
        $this->assertSame(
            SmsAutoReplySkipReason::OUTBOUND_AFTER_MESSAGE,
            $autoReply->check($innocentInbound->sms_conversation_id, $innocentInbound->id, null, 'Je vérifie.')->skipReason,
        );
    }

    public function test_watchdog_keeps_watching_a_former_member_without_its_own_line(): void
    {
        $this->assertSame(0, Artisan::call('sms:detach-line', ['organizer' => $this->kulturmId, '--no-interaction' => true]));

        $repository = app(OrganizerSmsSettingRepositoryInterface::class);
        $since = Carbon::now('UTC')->subDays(30)->toDateTimeString();

        $this->assertContains($this->kulturmId, $repository->findWithSmsLine(200, $since)
            ->map(fn (OrganizerSmsSettingDomainObject $settings) => $settings->getOrganizerId())->all());
        $this->assertNotContains($this->kulturmId, $repository->findWithSmsLine(200)
            ->map(fn (OrganizerSmsSettingDomainObject $settings) => $settings->getOrganizerId())->all());
    }

    private function detach(): void
    {
        putenv('TEST_NEW_LINE_API_KEY=uk_new');

        $this->assertSame(0, Artisan::call('sms:detach-line', [
            'organizer' => $this->kulturmId,
            '--from-number' => '0639 11 11 11',
            '--api-key-env' => 'TEST_NEW_LINE_API_KEY',
            '--no-interaction' => true,
        ]));

        $this->kulturmSettings->refresh();
    }

    private function receiveOnOldLine(string $contact, string $content, string $messageId = 'in-1'): void
    {
        app(HandleHttpSmsWebhookHandler::class)->handle(new HttpSmsWebhookDTO(
            webhookToken: $this->line->webhook_token,
            bearerToken: 'signed',
            eventType: HandleHttpSmsWebhookHandler::EVENT_RECEIVED,
            payload: json_encode(['data' => [
                'message_id' => $messageId,
                'contact' => $contact,
                'owner' => self::OLD_NUMBER,
                'content' => $content,
                'timestamp' => Carbon::now('UTC')->toIso8601String(),
            ]]),
        ));
    }

    private function kulturmConversation(): SmsConversation
    {
        return SmsConversation::query()->where('organizer_id', $this->kulturmId)->where('phone', self::KULTURM_CONTACT)->sole();
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

    private function conversation(int $organizerId, string $phone): SmsConversation
    {
        return SmsConversation::create([
            'account_id' => Organizer::find($organizerId)->account_id,
            'organizer_id' => $organizerId,
            'phone' => $phone,
            'status' => 'OPEN',
            'line_settings_id' => $this->line->id,
        ]);
    }

    private function message(SmsConversation $conversation, string $direction, string $content, int $daysAgo): SmsMessage
    {
        $at = Carbon::now('UTC')->subDays($daysAgo)->subMinute();

        return SmsMessage::create([
            'sms_conversation_id' => $conversation->id,
            'organizer_id' => $conversation->organizer_id,
            'direction' => $direction,
            'author_type' => $direction === 'OUTBOUND' ? 'HUMAN' : 'CONTACT',
            'content' => $content,
            'status' => $direction === 'OUTBOUND' ? 'SENT' : 'RECEIVED',
            'line_settings_id' => $this->line->id,
            'provider_timestamp' => $at,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }
}
