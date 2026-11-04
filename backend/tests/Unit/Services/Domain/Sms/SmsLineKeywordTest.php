<?php

namespace Tests\Unit\Services\Domain\Sms;

use HiEvents\DomainObjects\OrganizerSmsSettingDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Exceptions\SmsLineKeywordValidationException;
use HiEvents\Helper\SmsLineKeywordNormalizer;
use HiEvents\Models\Organizer;
use HiEvents\Models\OrganizerSmsSetting;
use HiEvents\Models\SmsConversation;
use HiEvents\Models\SmsMessage;
use HiEvents\Models\SmsUnattributedMessage;
use HiEvents\Services\Application\Handlers\Sms\DTO\HttpSmsWebhookDTO;
use HiEvents\Services\Application\Handlers\Sms\HandleHttpSmsWebhookHandler;
use HiEvents\Services\Domain\Sms\DTO\CreateSmsLineKeywordDTO;
use HiEvents\Services\Domain\Sms\DTO\UpdateSmsLineKeywordDTO;
use HiEvents\Services\Domain\Sms\HttpSmsWebhookSignatureVerifier;
use HiEvents\Services\Domain\Sms\SmsAlertService;
use HiEvents\Services\Domain\Sms\SmsAssistantService;
use HiEvents\Services\Domain\Sms\SmsLineKeywordService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

/**
 * « Envoie JUNGLE au 0639 25 06 60 »: on the PICHA line owned by Innocent, an SMS starting with a keyword of the Jungle
 * Café goes to the Jungle Café, never to Innocent nor to the unattributed SMS.
 */
class SmsLineKeywordTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    private const NOW = '2026-11-04 12:00:00';

    private const FILE_URL = 'http://file.picha.test';

    private const LINE = '+262639250660';

    private const CONTACT = '+262639651126';

    private int $innocentId;

    private int $kulturmId;

    private int $jungleId;

    private OrganizerSmsSetting $line;

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
        $this->jungleId = $this->organizer($event->account_id, 'Jungle');

        $this->line = $this->settings($this->innocentId, 'line-key', self::LINE);
        $this->settings($this->kulturmId, null, null, $this->line->id);
        $this->settings($this->jungleId, null, null, $this->line->id);

        $this->mock(HttpSmsWebhookSignatureVerifier::class)->shouldReceive('verify');
        $this->mock(SmsAlertService::class, function (MockInterface $alerts) {
            $alerts->shouldReceive('markPhoneActive')->andReturnUsing(fn (OrganizerSmsSettingDomainObject $settings) => $settings)->byDefault();
            $alerts->shouldReceive('notifyInbound')->byDefault();
        });
        $this->assistant = $this->mock(SmsAssistantService::class, function (MockInterface $assistant) {
            $assistant->shouldReceive('queueDraftIfEligible')->byDefault();
        });
        Mail::fake();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_unknown_number_texting_the_keyword_reaches_its_organizer_and_becomes_its_contact_without_any_reply(): void
    {
        Http::fake();
        $this->keyword($this->jungleId, 'JUNGLE');
        $this->assistant->shouldNotReceive('queueDraftIfEligible');

        $this->receive('in-1', 'Jungle');

        $message = SmsMessage::query()->where('provider_message_id', 'in-1')->sole();
        $this->assertSame($this->jungleId, $message->organizer_id);
        $this->assertSame('INBOUND', $message->direction);
        $this->assertSame('Jungle', $message->content);
        $this->assertSame('JUNGLE', $message->line_keyword);
        $this->assertSame($this->line->id, $message->line_settings_id);

        $conversation = SmsConversation::query()->where('phone', self::CONTACT)->sole();
        $this->assertSame($this->jungleId, $conversation->organizer_id);
        $this->assertSame($this->line->id, $conversation->line_settings_id);

        $this->assertSame(0, SmsUnattributedMessage::query()->count());
        $this->assertSame(0, SmsMessage::query()->where('organizer_id', $this->innocentId)->count());
        $this->assertSame(0, SmsConversation::query()->where('organizer_id', $this->innocentId)->count());

        $contact = DB::table('contacts')->where('organizer_id', $this->jungleId)->where('phone', self::CONTACT)->sole();
        $this->assertSame('SMS_KEYWORD', $contact->source);
        $this->assertNotNull($contact->sms_consent_at);
        $this->assertSame('SMS_KEYWORD', $contact->sms_consent_source);
        $this->assertNull($contact->sms_unsubscribed_at);
        $this->assertSame(0, DB::table('contacts')->where('organizer_id', $this->innocentId)->count());
        $this->assertTrue(DB::table('contact_tag_assignments')
            ->join('contact_tags', 'contact_tags.id', '=', 'contact_tag_assignments.contact_tag_id')
            ->where('contact_tag_assignments.contact_id', $contact->id)
            ->where('contact_tags.name', 'SMS JUNGLE')
            ->exists());

        Http::assertNothingSent();
        $this->assertSame(0, SmsMessage::query()->where('direction', 'OUTBOUND')->count());
    }

    public function test_the_whole_text_is_kept_and_a_second_keyword_sms_completes_the_same_contact(): void
    {
        $this->keyword($this->jungleId, 'JUNGLE');

        $this->receive('in-1', 'JUNGLE 2');
        $this->receive('in-2', 'jungle, je vote pour le 3 !');

        $this->assertSame(['JUNGLE 2', 'jungle, je vote pour le 3 !'], $this->inbound($this->jungleId)->orderBy('id')->pluck('content')->all());
        $this->assertSame(1, SmsConversation::query()->where('phone', self::CONTACT)->count());
        $this->assertSame(1, DB::table('contacts')->where('organizer_id', $this->jungleId)->where('phone', self::CONTACT)->count());
        $this->assertSame(1, DB::table('contact_tag_assignments')->count());
    }

    public function test_case_accents_and_punctuation_do_not_matter(): void
    {
        $this->keyword($this->jungleId, 'Jungle');

        foreach (['Jungle!', 'jungle', 'JÜNGLE', '  ¡Jungle,2', 'Jungle.'] as $i => $text) {
            $this->receive('in-'.$i, $text, '+26263965112'.$i);
        }

        $this->assertSame(5, $this->inbound($this->jungleId)->count());
        $this->assertSame(0, SmsUnattributedMessage::query()->count());
        $this->assertSame('JUNGLE', SmsLineKeywordNormalizer::firstWord('JÜNGLE! 2'));
        $this->assertSame('JUNGLE', SmsLineKeywordNormalizer::firstWord('...jungle?'));
        $this->assertNull(SmsLineKeywordNormalizer::firstWord(' !! '));
    }

    public function test_keyword_must_be_the_first_word(): void
    {
        $this->keyword($this->jungleId, 'JUNGLE');

        $this->receive('in-1', 'Bonjour JUNGLE');
        $this->receive('in-2', 'JUNGLECAFE', '+262639651127');

        $this->assertSame(0, $this->inbound($this->jungleId)->count());
        $this->assertSame(2, SmsUnattributedMessage::query()->count());
    }

    public function test_keyword_wins_over_a_more_recent_sms_of_another_organizer_and_the_next_sms_follow_it(): void
    {
        $this->keyword($this->jungleId, 'JUNGLE');
        $this->outbound($this->innocentId, hoursAgo: 2);

        $this->receive('in-1', 'JUNGLE');
        $this->assertSame(1, $this->inbound($this->jungleId)->count());

        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC')->addMinutes(5));
        $this->receive('in-2', '2');

        $this->assertSame(2, $this->inbound($this->jungleId)->count());
        $this->assertNull(SmsMessage::query()->where('provider_message_id', 'in-2')->sole()->line_keyword);
        $this->assertSame(0, $this->inbound($this->innocentId)->count());
        $this->assertSame(0, SmsUnattributedMessage::query()->count());
    }

    public function test_without_keyword_the_usual_attribution_is_unchanged(): void
    {
        $this->keyword($this->jungleId, 'JUNGLE');

        $this->receive('in-1', 'Bonjour, il reste des places ?');
        $this->assertSame(1, SmsUnattributedMessage::query()->where('provider_message_id', 'in-1')->count());
        $this->assertSame(0, SmsMessage::query()->count());

        $this->outbound($this->kulturmId, hoursAgo: 1, phone: '+262639651127');
        $this->receive('in-2', 'Merci', '+262639651127');
        $this->assertSame(1, $this->inbound($this->kulturmId)->count());
        $this->assertSame(0, $this->inbound($this->jungleId)->count());
        $this->assertSame(0, DB::table('contacts')->where('organizer_id', $this->jungleId)->count());
    }

    public function test_stop_after_the_keyword_unsubscribes_from_the_keyword_organizer_only(): void
    {
        $this->keyword($this->jungleId, 'JUNGLE');
        $this->outbound($this->innocentId, hoursAgo: 3);

        $this->receive('in-1', 'JUNGLE');
        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC')->addMinutes(10));
        $this->receive('in-2', 'STOP');

        $this->assertSame(['JUNGLE', 'STOP'], $this->inbound($this->jungleId)->orderBy('id')->pluck('content')->all());
        $this->assertSame(1, DB::table('sms_opt_outs')->where('organizer_id', $this->jungleId)->where('phone', self::CONTACT)->count());
        $this->assertSame(0, DB::table('sms_opt_outs')->where('organizer_id', $this->innocentId)->count());
        $this->assertSame(0, DB::table('sms_opt_outs')->where('organizer_id', $this->kulturmId)->count());
        $this->assertSame(0, SmsUnattributedMessage::query()->count());
    }

    public function test_stop_is_never_a_keyword_and_keeps_its_usual_handling(): void
    {
        DB::table('sms_line_keywords')->insert([
            'line_settings_id' => $this->line->id,
            'organizer_id' => $this->jungleId,
            'keyword' => 'STOP',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->receive('in-1', 'Stop');

        $this->assertSame(0, SmsMessage::query()->count());
        $unattributed = SmsUnattributedMessage::query()->sole();
        $this->assertTrue($unattributed->opt_out_applied);
        $this->assertSame(3, DB::table('sms_opt_outs')->where('phone', self::CONTACT)->count());
    }

    public function test_texting_the_keyword_after_stop_does_not_give_back_the_consent(): void
    {
        $this->keyword($this->jungleId, 'JUNGLE');
        DB::table('sms_opt_outs')->insert([
            'organizer_id' => $this->jungleId,
            'phone' => self::CONTACT,
            'source' => 'KEYWORD',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->receive('in-1', 'JUNGLE');

        $this->assertSame(1, $this->inbound($this->jungleId)->count());
        $this->assertNull(DB::table('contacts')->where('organizer_id', $this->jungleId)->sole()->sms_consent_at);
        $this->assertSame(1, DB::table('sms_opt_outs')->where('organizer_id', $this->jungleId)->count());
    }

    public function test_inactive_keyword_is_ignored(): void
    {
        $keyword = $this->keyword($this->jungleId, 'JUNGLE');
        app(SmsLineKeywordService::class)->update(new UpdateSmsLineKeywordDTO(
            organizerId: $this->jungleId,
            keywordId: $keyword->getId(),
            isActive: false,
        ));

        $this->receive('in-1', 'JUNGLE');

        $this->assertSame(0, $this->inbound($this->jungleId)->count());
        $this->assertSame(1, SmsUnattributedMessage::query()->count());
        $this->assertSame(0, DB::table('contacts')->where('organizer_id', $this->jungleId)->count());
    }

    public function test_keyword_of_an_organizer_no_longer_on_the_line_is_ignored(): void
    {
        $this->keyword($this->jungleId, 'JUNGLE');
        OrganizerSmsSetting::query()->where('organizer_id', $this->jungleId)->update(['shared_line_settings_id' => null]);

        $this->receive('in-1', 'JUNGLE');

        $this->assertSame(0, $this->inbound($this->jungleId)->count());
        $this->assertSame(1, SmsUnattributedMessage::query()->count());
    }

    public function test_reply_sent_by_number_from_the_line_phone_goes_to_the_line_owner_conversation_never_the_most_recent_one(): void
    {
        $this->keyword($this->jungleId, 'JUNGLE');
        $this->outbound($this->innocentId, hoursAgo: 5);
        $innocentConversation = SmsConversation::query()->where('organizer_id', $this->innocentId)->sole();

        $this->receive('in-1', 'JUNGLE');
        $jungleConversation = SmsConversation::query()->where('organizer_id', $this->jungleId)->sole();

        $this->providerStatus('message.phone.sent', 'picha-reply-1', null, 'Coucou, oui il reste des billets');

        $reply = SmsMessage::query()->where('provider_message_id', 'picha-reply-1')->sole();
        $this->assertSame($this->innocentId, $reply->organizer_id);
        $this->assertSame($innocentConversation->id, $reply->sms_conversation_id);
        $this->assertSame(1, SmsMessage::query()->where('sms_conversation_id', $jungleConversation->id)->count());
    }

    public function test_reply_through_the_queue_goes_to_the_conversation_of_the_organizer_the_queue_sent_it_for(): void
    {
        Http::fake([self::FILE_URL.'/v1/sms/950' => Http::response([
            'id' => 950, 'organisateur' => $this->innocentId, 'etat' => 'soumis', 'id_sms_picha' => 'picha-950',
        ], 200)]);
        $this->keyword($this->jungleId, 'JUNGLE');
        $this->outbound($this->innocentId, hoursAgo: 5);
        $innocentConversation = SmsConversation::query()->where('organizer_id', $this->innocentId)->sole();
        $this->receive('in-1', 'JUNGLE');

        $this->providerStatus('message.phone.sent', 'picha-950', 'file-950', 'Coucou');

        Http::assertSent(fn (Request $request) => $request->url() === self::FILE_URL.'/v1/sms/950');
        $reply = SmsMessage::query()->where('provider_message_id', 'picha-950')->sole();
        $this->assertSame($this->innocentId, $reply->organizer_id);
        $this->assertSame($innocentConversation->id, $reply->sms_conversation_id);
    }

    public function test_reply_of_the_keyword_organizer_through_the_queue_stays_in_its_conversation(): void
    {
        Http::fake([self::FILE_URL.'/v1/sms/951' => Http::response([
            'id' => 951, 'organisateur' => $this->jungleId, 'etat' => 'soumis', 'id_sms_picha' => 'picha-951',
        ], 200)]);
        $this->keyword($this->jungleId, 'JUNGLE');
        $this->outbound($this->innocentId, hoursAgo: 5);
        $this->receive('in-1', 'JUNGLE');
        $jungleConversation = SmsConversation::query()->where('organizer_id', $this->jungleId)->sole();

        $this->providerStatus('message.phone.sent', 'picha-951', 'file-951', 'Bienvenue au Jungle Café');

        $this->assertSame($jungleConversation->id, SmsMessage::query()->where('provider_message_id', 'picha-951')->sole()->sms_conversation_id);
    }

    public function test_keyword_is_normalized_and_unique_on_the_line(): void
    {
        $service = app(SmsLineKeywordService::class);

        $created = $this->keyword($this->jungleId, ' Jüngle! ');
        $this->assertSame('JUNGLE', $created->getKeyword());
        $this->assertTrue($created->getIsActive());
        $this->assertSame($this->line->id, $created->getLineSettingsId());

        $this->assertKeywordRejected($this->kulturmId, 'jungle', 'already used by another organizer');
        $this->assertKeywordRejected($this->jungleId, 'JUNGLE', 'You already have');
        $this->assertKeywordRejected($this->kulturmId, 'Jungle Café', 'single word');
        $this->assertKeywordRejected($this->kulturmId, 'J', 'letters or digits');
        $this->assertKeywordRejected($this->kulturmId, '2026', 'letters or digits');

        $this->assertSame('KULTURM', $service->create(new CreateSmsLineKeywordDTO(organizerId: $this->kulturmId, keyword: 'kulturm'))->getKeyword());
        $this->assertSame(['JUNGLE'], $service->view($this->jungleId)->keywords->map->getKeyword()->all());
        $this->assertTrue($service->view($this->jungleId)->available);
        $this->assertSame(self::LINE, $service->view($this->jungleId)->lineNumber);
    }

    public function test_reserved_words_are_refused(): void
    {
        foreach (['STOP', 'Arrêt', 'arret', 'UNSUBSCRIBE', 'Aide', 'HELP', 'oui', 'NON', 'START', 'R12', 'q3'] as $word) {
            $this->assertKeywordRejected($this->jungleId, $word, 'reserved');
        }

        $this->assertSame(0, DB::table('sms_line_keywords')->count());
    }

    public function test_keywords_need_a_shared_line(): void
    {
        $aloneId = $this->organizer(Organizer::find($this->innocentId)->account_id, 'Alone');
        $this->assertKeywordRejected($aloneId, 'ALONE', 'shared SMS line');

        $ownLineId = $this->organizer(Organizer::find($this->innocentId)->account_id, 'OwnLine');
        $this->settings($ownLineId, 'own-key', '+262639000001');
        $this->assertKeywordRejected($ownLineId, 'OWN', 'shared SMS line');
        $this->assertFalse(app(SmsLineKeywordService::class)->view($ownLineId)->available);

        $this->assertSame($this->line->id, $this->keyword($this->innocentId, 'INNOCENT')->getLineSettingsId());
    }

    public function test_an_organizer_only_changes_its_own_keywords(): void
    {
        $service = app(SmsLineKeywordService::class);
        $keyword = $this->keyword($this->jungleId, 'JUNGLE');

        try {
            $service->delete($this->kulturmId, $keyword->getId());
            $this->fail('Another organizer must not delete the keyword');
        } catch (ResourceNotFoundException) {
        }

        $updated = $service->update(new UpdateSmsLineKeywordDTO(
            organizerId: $this->jungleId,
            keywordId: $keyword->getId(),
            isActive: false,
            welcomeMessage: '  Bienvenue au Jungle Café  ',
        ));
        $this->assertFalse($updated->getIsActive());
        $this->assertSame('Bienvenue au Jungle Café', $updated->getWelcomeMessage());

        $service->delete($this->jungleId, $keyword->getId());
        $this->assertSame(0, DB::table('sms_line_keywords')->count());
    }

    private function assertKeywordRejected(int $organizerId, string $keyword, string $message): void
    {
        try {
            app(SmsLineKeywordService::class)->create(new CreateSmsLineKeywordDTO(organizerId: $organizerId, keyword: $keyword));
            $this->fail('The keyword '.$keyword.' must be refused');
        } catch (SmsLineKeywordValidationException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }

    private function keyword(int $organizerId, string $keyword)
    {
        return app(SmsLineKeywordService::class)->create(new CreateSmsLineKeywordDTO(organizerId: $organizerId, keyword: $keyword));
    }

    private function receive(string $messageId, string $content, string $contact = self::CONTACT): void
    {
        app(HandleHttpSmsWebhookHandler::class)->handle(new HttpSmsWebhookDTO(
            webhookToken: $this->line->webhook_token,
            bearerToken: 'signed',
            eventType: HandleHttpSmsWebhookHandler::EVENT_RECEIVED,
            payload: json_encode(['data' => [
                'message_id' => $messageId,
                'contact' => $contact,
                'owner' => self::LINE,
                'content' => $content,
                'timestamp' => Carbon::now('UTC')->toIso8601String(),
            ]]),
        ));
    }

    private function providerStatus(string $type, string $providerId, ?string $requestId, string $content): void
    {
        app(HandleHttpSmsWebhookHandler::class)->handle(new HttpSmsWebhookDTO(
            webhookToken: $this->line->webhook_token,
            bearerToken: 'signed',
            eventType: $type,
            payload: json_encode(['data' => array_filter([
                'id' => $providerId,
                'contact' => self::CONTACT,
                'owner' => self::LINE,
                'content' => $content,
                'request_id' => $requestId,
                'timestamp' => Carbon::now('UTC')->toIso8601String(),
            ], fn ($value) => $value !== null)]),
        ));
    }

    private function inbound(int $organizerId)
    {
        return SmsMessage::query()->where('organizer_id', $organizerId)->where('direction', 'INBOUND');
    }

    private function outbound(int $organizerId, int $hoursAgo, string $phone = self::CONTACT): void
    {
        $conversation = SmsConversation::query()->firstOrCreate(
            ['organizer_id' => $organizerId, 'phone' => $phone],
            ['account_id' => Organizer::find($organizerId)->account_id, 'status' => 'OPEN', 'line_settings_id' => $this->line->id],
        );
        $at = Carbon::now('UTC')->subHours($hoursAgo);

        SmsMessage::create([
            'sms_conversation_id' => $conversation->id,
            'organizer_id' => $organizerId,
            'direction' => 'OUTBOUND',
            'author_type' => 'CAMPAIGN',
            'content' => 'Halloween : derniers billets',
            'status' => 'SENT',
            'line_settings_id' => $this->line->id,
            'provider_timestamp' => $at,
            'created_at' => $at,
            'updated_at' => $at,
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
