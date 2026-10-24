<?php

namespace Tests\Unit\Services\Domain\Sms\Team;

use HiEvents\DomainObjects\Enums\OrganizerTeamContactRole;
use HiEvents\Jobs\Sms\GenerateSmsDraftJob;
use HiEvents\Jobs\Sms\SendSmsInboundAlertJob;
use HiEvents\Mail\Sms\PlatformSupervisionAlertMail;
use HiEvents\Models\Organizer;
use HiEvents\Models\OrganizerSmsSetting;
use HiEvents\Models\SmsConversation;
use HiEvents\Models\SmsMessage;
use HiEvents\Services\Application\Handlers\Sms\DTO\HttpSmsWebhookDTO;
use HiEvents\Services\Application\Handlers\Sms\HandleHttpSmsWebhookHandler;
use HiEvents\Services\Domain\Sms\HttpSmsWebhookSignatureVerifier;
use HiEvents\Services\Domain\Sms\OrganizerAssistant\OrganizerAssistantReplyGenerator;
use HiEvents\Services\Domain\Sms\Team\OrganizerTeamContactService;
use HiEvents\Services\Infrastructure\AI\ClaudeStructuredOutputClient;
use HiEvents\Services\Infrastructure\AI\DTO\ClaudeStructuredAnswerDTO;
use HiEvents\Services\Infrastructure\HttpSms\HttpSmsClient;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Mockery;
use Mockery\MockInterface;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

/**
 * A shared line (PICHA) used by two organizers: « ARRM » and « Kulturm ».
 */
class SmsTeamAndOrganizerAssistantTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    private const LINE_NUMBER = '+262639000000';

    private const RESPONSABLE = '+262639689691';

    private const COPY = '+262639250206';

    private const SUPERVISOR = '+262639780773';

    private const CUSTOMER = '+262639111222';

    private int $arrmId;

    private int $kulturmId;

    private OrganizerSmsSetting $line;

    private OrganizerSmsSetting $arrmSettings;

    private array $sent = [];

    private ClaudeStructuredOutputClient|MockInterface $claude;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-24 12:00:00', 'UTC'));
        DB::table('organizer_sms_settings')->update(['api_key' => null, 'shared_line_settings_id' => null]);
        config([
            'services.platform_supervisor.phones' => self::SUPERVISOR,
            'services.sms_watchdog.alert_email' => 'veille@picha.test',
            'services.anthropic.api_key' => 'test-key',
            'app.frontend_url' => 'https://ticket.picha.test',
        ]);

        [$event] = $this->createEventWithProduct();
        $this->kulturmId = $event->organizer_id;
        $this->arrmId = Organizer::create([
            'account_id' => $event->account_id,
            'name' => 'ARRM',
            'email' => 'arrm@example.test',
            'currency' => 'EUR',
            'timezone' => 'Indian/Mayotte',
        ])->id;

        $this->line = $this->settings($this->kulturmId, 'uk_line', self::LINE_NUMBER);
        $this->arrmSettings = $this->settings($this->arrmId, null, null, $this->line->id);

        $this->mock(HttpSmsClient::class, function (MockInterface $client) {
            $client->shouldReceive('sendMessage')->andReturnUsing(function (string $apiKey, string $from, string $to, string $content) {
                $this->sent[] = compact('from', 'to', 'content');

                return 'provider-out-'.count($this->sent);
            });
        });
        $this->mock(HttpSmsWebhookSignatureVerifier::class)->shouldReceive('verify');

        $this->claude = Mockery::mock(ClaudeStructuredOutputClient::class);
        $this->claude->shouldReceive('isConfigured')->andReturnTrue()->byDefault();
        $this->app->instance(ClaudeStructuredOutputClient::class, $this->claude);

        Bus::fake([SendSmsInboundAlertJob::class, GenerateSmsDraftJob::class]);
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_team_sms_never_goes_to_the_customer_assistant_and_never_moves_the_attribution(): void
    {
        $this->line->update(['assistant_enabled' => true]);
        $this->arrmSettings->update(['assistant_enabled' => true]);
        $this->team($this->arrmId, self::COPY, OrganizerTeamContactRole::COPY);

        $this->receive(self::COPY, 'Il y a combien d inscrits ?', 'in-1');

        $message = SmsMessage::query()->where('provider_message_id', 'in-1')->sole();
        $this->assertSame($this->arrmId, $message->organizer_id);
        $conversation = SmsConversation::find($message->sms_conversation_id);
        $this->assertTrue((bool) $conversation->is_team);
        Bus::assertNotDispatched(GenerateSmsDraftJob::class);
        $this->assertSame([], $this->sent);

        $this->outbound($this->arrmId, self::CUSTOMER, 'Rappel ARRM', minutesAgo: 30);
        $this->outbound($this->kulturmId, self::CUSTOMER, 'Rappel Kulturm', minutesAgo: 60);
        $this->outbound($this->kulturmId, self::COPY, 'Message Kulturm au contact', minutesAgo: 5);

        $this->receive(self::COPY, 'Merci', 'in-2');
        $this->assertSame($this->arrmId, SmsMessage::query()->where('provider_message_id', 'in-2')->sole()->organizer_id);

        $this->receive(self::CUSTOMER, 'Je viens à la marche', 'in-3');
        $this->assertSame($this->arrmId, SmsMessage::query()->where('provider_message_id', 'in-3')->sole()->organizer_id);
        Bus::assertDispatched(GenerateSmsDraftJob::class);
    }

    public function test_team_reply_from_an_organizer_does_not_count_as_last_who_wrote(): void
    {
        $this->team($this->arrmId, self::COPY, OrganizerTeamContactRole::COPY);
        $this->outbound($this->kulturmId, self::CUSTOMER, 'Rappel Kulturm', minutesAgo: 60);

        $conversation = SmsConversation::create([
            'account_id' => Organizer::find($this->arrmId)->account_id,
            'organizer_id' => $this->arrmId,
            'phone' => self::CUSTOMER,
            'status' => 'OPEN',
            'is_team' => true,
            'line_settings_id' => $this->line->id,
        ]);
        $this->message($conversation, 'OUTBOUND', 'Message dans une conversation équipe', minutesAgo: 1);

        $this->receive(self::CUSTOMER, 'Bonjour', 'in-1');

        $this->assertSame($this->kulturmId, SmsMessage::query()->where('provider_message_id', 'in-1')->sole()->organizer_id);
    }

    public function test_number_in_two_teams_goes_to_the_latest_team_conversation(): void
    {
        $this->team($this->arrmId, self::COPY, OrganizerTeamContactRole::COPY);
        $this->team($this->kulturmId, self::COPY, OrganizerTeamContactRole::ORGANIZER);

        $arrm = SmsConversation::query()->where('organizer_id', $this->arrmId)->where('phone', self::COPY)->first()
            ?? $this->teamConversation($this->arrmId, self::COPY);
        $kulturm = $this->teamConversation($this->kulturmId, self::COPY);
        $arrm->update(['last_message_at' => Carbon::now()->subMinutes(2)]);
        $kulturm->update(['last_message_at' => Carbon::now()->subMinutes(20)]);

        $this->receive(self::COPY, 'Ok pour moi', 'in-1');

        $this->assertSame($this->arrmId, SmsMessage::query()->where('provider_message_id', 'in-1')->sole()->organizer_id);
    }

    public function test_supervisor_sms_reaches_no_assistant_and_stays_visible(): void
    {
        $this->line->update(['assistant_enabled' => true, 'organizer_assistant_enabled' => true]);
        $this->outbound($this->arrmId, self::SUPERVISOR, 'Alerte', minutesAgo: 3);

        $this->receive(self::SUPERVISOR, 'Vu, je regarde', 'in-1');

        $message = SmsMessage::query()->where('provider_message_id', 'in-1')->sole();
        $this->assertSame($this->arrmId, $message->organizer_id);
        $this->assertFalse((bool) SmsConversation::find($message->sms_conversation_id)->is_team);
        Bus::assertNotDispatched(GenerateSmsDraftJob::class);
        $this->claude->shouldNotHaveReceived('askWithUsage');
    }

    public function test_supervisor_number_cannot_join_a_team(): void
    {
        $this->assertSame(1, Artisan::call('sms:team-contact', [
            'organizer' => $this->arrmId,
            'phone' => '0639 78 07 73',
            '--role' => 'COPY',
        ]));
        $this->assertSame(0, DB::table('organizer_team_contacts')->count());
    }

    public function test_team_contact_command_adds_updates_and_removes(): void
    {
        $this->assertSame(0, Artisan::call('sms:team-contact', [
            'organizer' => $this->arrmId,
            'phone' => '0639 68 96 91',
            '--name' => 'Responsable ARRM',
        ]));
        $this->assertSame(0, Artisan::call('sms:team-contact', [
            'organizer' => $this->arrmId,
            'phone' => self::COPY,
            '--name' => 'Innocent',
            '--role' => 'COPY',
        ]));

        $this->assertSame(
            [self::RESPONSABLE => 'ORGANIZER', self::COPY => 'COPY'],
            DB::table('organizer_team_contacts')->where('organizer_id', $this->arrmId)->orderBy('id')->pluck('role', 'phone')->all(),
        );

        $this->assertSame(0, Artisan::call('sms:team-contact', ['organizer' => $this->arrmId, 'phone' => self::COPY, '--remove' => true]));
        $this->assertSame(1, DB::table('organizer_team_contacts')->where('organizer_id', $this->arrmId)->count());
    }

    public function test_organizer_contact_gets_an_answer_from_anicka(): void
    {
        $this->arrmSettings->update(['organizer_assistant_enabled' => true, 'organizer_assistant_instructions' => 'Marche des Amis']);
        $this->team($this->arrmId, self::RESPONSABLE, OrganizerTeamContactRole::ORGANIZER);
        $this->answer('Les inscriptions marchent bien, 12 billets payés.', needsHuman: false, summary: 'Question sur les inscrits');

        $this->receive(self::RESPONSABLE, 'Ça marche les inscriptions ?', 'in-1');

        $this->assertCount(1, $this->sent);
        $this->assertSame(self::RESPONSABLE, $this->sent[0]['to']);
        $this->assertSame(self::LINE_NUMBER, $this->sent[0]['from']);
        $this->assertSame("Les inscriptions marchent bien, 12 billets payés.\nAnicka", $this->sent[0]['content']);

        $inbound = SmsMessage::query()->where('provider_message_id', 'in-1')->sole();
        $reply = SmsMessage::query()->where('auto_reply_to_message_id', $inbound->id)->sole();
        $this->assertSame('AGENT', $reply->author_type);
        $this->assertSame('ANSWER', $reply->auto_reply_type);
        $this->assertSame('OPEN', SmsConversation::find($inbound->sms_conversation_id)->status);
        Mail::assertNotQueued(PlatformSupervisionAlertMail::class);
    }

    public function test_needs_human_sends_a_holding_reply_and_alerts_the_supervision_at_once(): void
    {
        $this->arrmSettings->update(['organizer_assistant_enabled' => true]);
        $this->team($this->arrmId, self::RESPONSABLE, OrganizerTeamContactRole::ORGANIZER);
        $this->answer('Je vois avec l\'équipe et je reviens vers vous.', needsHuman: true, summary: 'Veut lancer une campagne SMS demain');

        $this->receive(self::RESPONSABLE, 'Tu peux lancer la campagne demain matin ?', 'in-1');

        $this->assertSame(self::RESPONSABLE, $this->sent[0]['to']);
        $this->assertSame("Je regarde ça et je reviens vers vous très vite.\nAnicka", $this->sent[0]['content']);

        $this->assertSame(self::SUPERVISOR, $this->sent[1]['to']);
        $this->assertStringContainsString('ARRM', $this->sent[1]['content']);
        $this->assertStringContainsString('Veut lancer une campagne SMS demain', $this->sent[1]['content']);

        $conversation = SmsConversation::query()->where('organizer_id', $this->arrmId)->where('phone', self::RESPONSABLE)->sole();
        $this->assertStringContainsString('/manage/organizer/'.$this->arrmId.'/sms?conversation='.$conversation->id, $this->sent[1]['content']);
        $this->assertSame('NEEDS_HUMAN', $conversation->status);
        $this->assertTrue((bool) SmsMessage::query()->where('content', $this->sent[1]['content'])->sole()->is_supervision);

        Mail::assertQueued(PlatformSupervisionAlertMail::class, fn (PlatformSupervisionAlertMail $mail) => $mail->hasTo('veille@picha.test'));
    }

    public function test_copy_contact_gets_no_automatic_answer(): void
    {
        $this->arrmSettings->update(['organizer_assistant_enabled' => true]);
        $this->team($this->arrmId, self::COPY, OrganizerTeamContactRole::COPY);
        $this->claude->shouldNotReceive('askWithUsage');

        $this->receive(self::COPY, 'Bien reçu', 'in-1');

        $this->assertSame([], $this->sent);
    }

    public function test_assistant_off_by_default_answers_nobody(): void
    {
        $this->team($this->arrmId, self::RESPONSABLE, OrganizerTeamContactRole::ORGANIZER);
        $this->claude->shouldNotReceive('askWithUsage');

        $this->receive(self::RESPONSABLE, 'Bonjour Anicka', 'in-1');

        $this->assertSame([], $this->sent);
    }

    public function test_interpret_cleans_the_answer_and_never_lets_a_team_mention_through(): void
    {
        $result = OrganizerAssistantReplyGenerator::interpret(['reply' => 'Oui — tout est bon · merci', 'needs_human' => false, 'summary' => 'ok']);
        $this->assertSame("Oui, tout est bon, merci\nAnicka", $result->reply);
        $this->assertFalse($result->needsHuman);

        $result = OrganizerAssistantReplyGenerator::interpret(['reply' => 'Je vois avec l\'équipe', 'needs_human' => false, 'summary' => 'x']);
        $this->assertTrue($result->needsHuman);
        $this->assertSame(OrganizerAssistantReplyGenerator::holdingReply(), $result->reply);

        $this->assertNull(OrganizerAssistantReplyGenerator::interpret(['reply' => 'x']));
    }

    public function test_intro_command_shows_the_text_and_sends_only_with_send(): void
    {
        $this->team($this->arrmId, self::RESPONSABLE, OrganizerTeamContactRole::ORGANIZER);
        $this->team($this->arrmId, self::COPY, OrganizerTeamContactRole::COPY);

        $this->assertSame(0, Artisan::call('sms:organizer-intro', ['organizer' => $this->arrmId]));
        $this->assertStringContainsString('je suis Anicka', Artisan::output());
        $this->assertSame([], $this->sent);

        $this->assertSame(0, Artisan::call('sms:organizer-intro', ['organizer' => $this->arrmId, '--text' => 'Bonjour, ici Anicka.', '--send' => true]));
        $this->assertSame([['from' => self::LINE_NUMBER, 'to' => self::RESPONSABLE, 'content' => 'Bonjour, ici Anicka.']], $this->sent);
    }

    private function answer(string $reply, bool $needsHuman, string $summary): void
    {
        $this->claude->shouldReceive('askWithUsage')->once()->andReturn(new ClaudeStructuredAnswerDTO(
            data: ['reply' => $reply, 'needs_human' => $needsHuman, 'summary' => $summary],
            model: 'claude-haiku-5-5',
            inputTokens: 100,
            outputTokens: 20,
        ));
    }

    private function team(int $organizerId, string $phone, OrganizerTeamContactRole $role): void
    {
        app(OrganizerTeamContactService::class)->save($organizerId, $phone, null, $role);
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

    private function teamConversation(int $organizerId, string $phone): SmsConversation
    {
        return SmsConversation::query()->where('organizer_id', $organizerId)->where('phone', $phone)->first()
            ?? SmsConversation::create([
                'account_id' => Organizer::find($organizerId)->account_id,
                'organizer_id' => $organizerId,
                'phone' => $phone,
                'status' => 'OPEN',
                'is_team' => true,
                'line_settings_id' => $this->line->id,
            ]);
    }

    private function outbound(int $organizerId, string $phone, string $content, int $minutesAgo): void
    {
        $conversation = SmsConversation::query()->where('organizer_id', $organizerId)->where('phone', $phone)->first()
            ?? SmsConversation::create([
                'account_id' => Organizer::find($organizerId)->account_id,
                'organizer_id' => $organizerId,
                'phone' => $phone,
                'status' => 'OPEN',
                'line_settings_id' => $this->line->id,
            ]);

        $this->message($conversation, 'OUTBOUND', $content, $minutesAgo);
    }

    private function message(SmsConversation $conversation, string $direction, string $content, int $minutesAgo): void
    {
        $at = Carbon::now('UTC')->subMinutes($minutesAgo);

        SmsMessage::create([
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
