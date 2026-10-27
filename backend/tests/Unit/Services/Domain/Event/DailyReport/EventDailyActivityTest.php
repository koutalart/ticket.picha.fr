<?php

namespace Tests\Unit\Services\Domain\Event\DailyReport;

use HiEvents\DomainObjects\Enums\OrganizerTeamContactRole;
use HiEvents\DomainObjects\SmsCampaignDomainObject;
use HiEvents\Models\Event;
use HiEvents\Models\OrganizerSmsSetting;
use HiEvents\Models\Product;
use HiEvents\Models\ProductPrice;
use HiEvents\Services\Domain\Event\DailyReport\EventDailyActivityService;
use HiEvents\Services\Domain\Event\DailyReport\EventDailyReportService;
use HiEvents\Services\Domain\Sms\Team\OrganizerTeamContactService;
use HiEvents\Services\Infrastructure\HttpSms\HttpSmsClient;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

class EventDailyActivityTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    private const TIMEZONE = 'Indian/Mayotte';

    private const LINE_NUMBER = '+262639000000';

    private const RESPONSABLE = '+262639689691';

    private const COPY = '+262639250206';

    private const SUPERVISOR = '+262639780773';

    private Event $event;

    private Product $ticket;

    private ProductPrice $ticketPrice;

    private Carbon $local;

    private array $sent = [];

    private int $providerIds = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->local = Carbon::now(self::TIMEZONE)->setTime(20, 0);
        Carbon::setTestNow($this->local->copy()->utc());
        DB::table('organizer_sms_settings')->update(['api_key' => null, 'shared_line_settings_id' => null]);
        config(['services.platform_supervisor.phones' => self::SUPERVISOR]);

        [$this->event, $this->ticket, $this->ticketPrice] = $this->createEventWithProduct(price: 15.00);
        DB::table('events')->where('id', $this->event->id)->update(['title' => 'Marche des Amis', 'currency' => 'EUR', 'timezone' => self::TIMEZONE]);

        OrganizerSmsSetting::create([
            'account_id' => $this->event->account_id,
            'organizer_id' => $this->event->organizer_id,
            'webhook_token' => Str::random(40),
            'webhook_signing_key' => 'key',
            'api_key' => 'uk_line',
            'from_number' => self::LINE_NUMBER,
        ]);

        $this->mock(HttpSmsClient::class, function (MockInterface $client) {
            $client->shouldReceive('sendMessage')->andReturnUsing(function (string $apiKey, string $from, string $to, string $content) {
                $this->sent[] = compact('from', 'to', 'content');

                return 'provider-'.(++$this->providerIds);
            });
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_topics_are_found_with_keywords_without_any_model(): void
    {
        $this->assertSame(EventDailyActivityService::TOPIC_REGISTRATION, EventDailyActivityService::topic("Bonjour je n'arrive pas à m'inscrire"));
        $this->assertSame(EventDailyActivityService::TOPIC_TSHIRT_PICKUP, EventDailyActivityService::topic('Où récupérer le tee-shirt ?'));
        $this->assertSame(EventDailyActivityService::TOPIC_TSHIRT, EventDailyActivityService::topic('Quelle taille de T-shirt ?'));
        $this->assertSame(EventDailyActivityService::TOPIC_REFUND, EventDailyActivityService::topic('Je voudrais être remboursé'));
        $this->assertSame(EventDailyActivityService::TOPIC_PRACTICAL, EventDailyActivityService::topic("C'est à quelle heure le départ ?"));
        $this->assertSame(EventDailyActivityService::TOPIC_OTHER, EventDailyActivityService::topic('Merci beaucoup'));
    }

    public function test_summary_line_matches_the_expected_wording(): void
    {
        $this->assertSame(
            "Côté échanges aujourd'hui : 3 confirmations d'inscription envoyées, 2 personnes aidées pour s'inscrire, 1 question sur le retrait du tee-shirt. Aucune demande de remboursement en cours.",
            EventDailyActivityService::summaryLine(3, [
                EventDailyActivityService::TOPIC_TSHIRT_PICKUP => 1,
                EventDailyActivityService::TOPIC_REGISTRATION => 2,
            ], 0),
        );
        $this->assertSame("Aucune question reçue aujourd'hui.", EventDailyActivityService::summaryLine(0, [], 0));
        $this->assertSame(
            "Aucune question reçue aujourd'hui. 2 demandes de remboursement en cours.",
            EventDailyActivityService::summaryLine(0, [], 2),
        );
        $this->assertSame(
            "Côté échanges aujourd'hui : 1 confirmation d'inscription envoyée, aucune question reçue. 1 demande de remboursement en cours.",
            EventDailyActivityService::summaryLine(1, [], 1),
        );
        $this->assertSame(
            "Côté échanges aujourd'hui : 2 questions traitées. Aucune demande de remboursement en cours.",
            EventDailyActivityService::summaryLine(0, [EventDailyActivityService::TOPIC_OTHER => 2], 0),
        );
    }

    public function test_step_labels_use_the_reminder_key_or_the_campaign_name(): void
    {
        $this->assertSame('rappel J-7', EventDailyActivityService::stepLabel((new SmsCampaignDomainObject)->setAutoKey('J-7')->setName('Rappel J-7')));
        $this->assertSame('rappel du jour J', EventDailyActivityService::stepLabel((new SmsCampaignDomainObject)->setAutoKey('J')->setName('Rappel le jour même')));
        $this->assertSame('rappel J-3', EventDailyActivityService::stepLabel((new SmsCampaignDomainObject)->setName('Relance J-3 inscrits')));
        $this->assertSame('Infos parking, accès', EventDailyActivityService::stepLabel((new SmsCampaignDomainObject)->setName('Infos parking — accès')));
    }

    public function test_activity_of_the_day_counts_only_this_event_and_leaves_team_and_supervision_aside(): void
    {
        $this->teamOf(self::RESPONSABLE, OrganizerTeamContactRole::ORGANIZER);
        $this->teamOf(self::COPY, OrganizerTeamContactRole::COPY);

        $this->confirmation($this->local->copy()->setTime(9, 0));
        $this->confirmation($this->local->copy()->setTime(15, 30));
        $this->confirmation($this->local->copy()->subDay()->setTime(15, 30));

        $this->inboundSms('+262639111111', "Bonjour, je n'arrive pas à finir mon inscription", $this->local->copy()->setTime(10, 0));
        $this->inboundSms('+262639111111', 'Le lien ne marche pas', $this->local->copy()->setTime(10, 5));
        $this->inboundSms('+262639222222', 'Je voudrais réserver deux places', $this->local->copy()->subDay()->setTime(18, 0));
        $this->inboundSms(self::RESPONSABLE, 'Combien d\'inscrits ?', $this->local->copy()->setTime(11, 0), isTeam: true);
        $this->inboundSms(self::SUPERVISOR, 'Point sur la journée ?', $this->local->copy()->setTime(11, 30));
        $this->inboundSms('+262639333333', 'STOP', $this->local->copy()->setTime(12, 0));

        $this->liveChat(['Où est-ce qu\'on récupère le tee-shirt ?'], $this->local->copy()->setTime(14, 0));
        $this->liveChat(['Bonjour', 'Le paiement est refusé'], $this->local->copy()->setTime(16, 0));
        [$otherEvent] = $this->createEventWithProduct();
        $this->liveChat(['Inscription pour un autre événement'], $this->local->copy()->setTime(16, 0), $otherEvent->id);

        $this->refundRequest('AWAITING_APPROVAL');
        $this->refundRequest('REFUNDED');

        $activity = app(EventDailyReportService::class)->buildActivity($this->event->id);

        $this->assertSame(2, $activity->stats->confirmationsSent);
        $this->assertSame(1, $activity->stats->pendingRefunds);
        $this->assertEquals([
            EventDailyActivityService::TOPIC_REGISTRATION => 2,
            EventDailyActivityService::TOPIC_TSHIRT_PICKUP => 1,
        ], $activity->topics);
        $this->assertSame(
            "Côté échanges aujourd'hui : 2 confirmations d'inscription envoyées, 2 personnes aidées pour s'inscrire, 1 question sur le retrait du tee-shirt. 1 demande de remboursement en cours.\n\nAnicka",
            $activity->text,
        );
        $this->assertDoesNotMatchRegularExpression('/[•·—–]/u', $activity->text);
    }

    public function test_next_steps_list_the_scheduled_campaigns_in_local_time(): void
    {
        $weekBefore = $this->local->copy()->addDays(7)->setTime(18, 0);
        $dayBefore = $this->local->copy()->addDays(13)->setTime(18, 0);
        $this->campaign('Rappel J-7', 'J-7', 'SCHEDULED', $weekBefore);
        $this->campaign('Infos pratiques', null, 'SCHEDULED', $dayBefore);
        $this->campaign('Déjà parti', null, 'SENT', $this->local->copy()->addDays(2));
        $this->campaign('Annulé', null, 'CANCELLED', $this->local->copy()->addDays(3));
        $this->campaign('Passé', null, 'SCHEDULED', $this->local->copy()->subHour());

        $activity = app(EventDailyReportService::class)->buildActivity($this->event->id);

        $this->assertSame([
            'rappel J-7 le '.$weekBefore->format('d/m').' à 18h00',
            'Infos pratiques le '.$dayBefore->format('d/m').' à 18h00',
        ], $activity->nextSteps);
        $this->assertSame(
            "Aucune question reçue aujourd'hui.\nProchaines étapes : rappel J-7 le ".$weekBefore->format('d/m').' à 18h00, Infos pratiques le '.$dayBefore->format('d/m')." à 18h00.\n\nAnicka",
            $activity->text,
        );
    }

    public function test_activity_sms_follows_the_report_only_when_turned_on(): void
    {
        $this->teamOf(self::RESPONSABLE, OrganizerTeamContactRole::ORGANIZER);
        $this->teamOf(self::COPY, OrganizerTeamContactRole::COPY);
        $service = app(EventDailyReportService::class);
        $service->configure($this->event->id, enabled: true, sendTime: '19:00', copySupervisors: true);

        $this->assertCount(1, $service->sendDue());
        $this->assertSame([self::RESPONSABLE, self::COPY, self::SUPERVISOR], array_column($this->sent, 'to'));

        $this->sent = [];
        DB::table('event_daily_reports')->where('event_id', $this->event->id)->update(['last_sent_on' => null]);
        $service->configure($this->event->id, activityEnabled: true);

        $results = $service->sendDue();
        $this->assertCount(1, $results);
        $this->assertNotNull($results[0]->activity);
        $this->assertSame(
            [self::RESPONSABLE, self::COPY, self::SUPERVISOR, self::RESPONSABLE, self::COPY, self::SUPERVISOR],
            array_column($this->sent, 'to'),
        );
        $this->assertStringStartsWith('Marche des Amis, point du', $this->sent[0]['content']);
        $this->assertSame("Aucune question reçue aujourd'hui.\n\nAnicka", $this->sent[3]['content']);
        $this->assertSame(3, DB::table('sms_messages')->where('content', 'like', 'Aucune question%')->count());
        $this->assertSame(0, DB::table('sms_messages')->where('awaits_reply', true)->count());
        $this->assertSame([], $service->sendDue());
    }

    public function test_dry_run_shows_the_activity_and_the_command_turns_it_on(): void
    {
        $this->teamOf(self::RESPONSABLE, OrganizerTeamContactRole::ORGANIZER);

        $this->assertSame(0, Artisan::call('events:daily-report', ['event' => $this->event->id, '--dry-run' => true]));
        $output = Artisan::output();
        $this->assertStringContainsString('Activity SMS (off, shown for review)', $output);
        $this->assertStringContainsString("Aucune question reçue aujourd'hui.", $output);
        $this->assertSame([], $this->sent);

        $this->assertSame(0, Artisan::call('events:daily-report', ['event' => $this->event->id, '--activity' => true]));
        $this->assertTrue((bool) DB::table('event_daily_reports')->where('event_id', $this->event->id)->value('activity_enabled'));
        $this->assertFalse((bool) DB::table('event_daily_reports')->where('event_id', $this->event->id)->value('enabled'));

        $this->assertSame(0, Artisan::call('events:daily-report', ['event' => $this->event->id, '--no-activity' => true]));
        $this->assertFalse((bool) DB::table('event_daily_reports')->where('event_id', $this->event->id)->value('activity_enabled'));
        $this->assertSame([], $this->sent);
    }

    private function teamOf(string $phone, OrganizerTeamContactRole $role): void
    {
        app(OrganizerTeamContactService::class)->save($this->event->organizer_id, $phone, null, $role);
    }

    private function confirmation(Carbon $at): void
    {
        $orderId = DB::table('orders')->insertGetId([
            'short_id' => 'o_'.Str::random(13),
            'public_id' => 'O-'.strtoupper(Str::random(7)),
            'event_id' => $this->event->id,
            'total_before_additions' => 15,
            'total_gross' => 15,
            'currency' => 'EUR',
            'first_name' => 'Awa',
            'last_name' => 'Saïd',
            'email' => 'awa@example.test',
            'status' => 'COMPLETED',
            'created_at' => $at->copy()->utc(),
            'updated_at' => $at->copy()->utc(),
        ]);

        $attendeeId = DB::table('attendees')->insertGetId([
            'short_id' => 'a_'.Str::random(13),
            'public_id' => 'A-'.strtoupper(Str::random(7)),
            'first_name' => 'Awa',
            'last_name' => 'Saïd',
            'email' => 'awa@example.test',
            'order_id' => $orderId,
            'product_id' => $this->ticket->id,
            'product_price_id' => $this->ticketPrice->id,
            'event_id' => $this->event->id,
            'status' => 'ACTIVE',
            'created_at' => $at->copy()->utc(),
            'updated_at' => $at->copy()->utc(),
        ]);

        DB::table('sms_registration_confirmations')->insert([
            'organizer_id' => $this->event->organizer_id,
            'event_id' => $this->event->id,
            'order_id' => $orderId,
            'attendee_id' => $attendeeId,
            'phone' => '+2626390'.random_int(10000, 99999),
            'status' => 'SENT',
            'created_at' => $at->copy()->utc(),
            'updated_at' => $at->copy()->utc(),
        ]);
    }

    private function conversation(string $phone, bool $isTeam = false): int
    {
        $existing = DB::table('sms_conversations')->where('organizer_id', $this->event->organizer_id)->where('phone', $phone)->value('id');
        if ($existing !== null) {
            return (int) $existing;
        }

        return DB::table('sms_conversations')->insertGetId([
            'account_id' => $this->event->account_id,
            'organizer_id' => $this->event->organizer_id,
            'phone' => $phone,
            'status' => 'OPEN',
            'is_team' => $isTeam,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function inboundSms(string $phone, string $content, Carbon $at, bool $isTeam = false): void
    {
        DB::table('sms_messages')->insert([
            'sms_conversation_id' => $this->conversation($phone, $isTeam),
            'organizer_id' => $this->event->organizer_id,
            'direction' => 'INBOUND',
            'author_type' => 'CONTACT',
            'content' => $content,
            'status' => 'RECEIVED',
            'provider_timestamp' => $at->copy()->utc(),
            'created_at' => $at->copy()->utc(),
            'updated_at' => $at->copy()->utc(),
        ]);
    }

    /**
     * @param  array<int, string>  $messages
     */
    private function liveChat(array $messages, Carbon $at, ?int $eventId = null): void
    {
        $sessionId = DB::table('live_chat_sessions')->insertGetId([
            'public_id' => Str::random(40),
            'account_id' => $this->event->account_id,
            'organizer_id' => $this->event->organizer_id,
            'event_id' => $eventId ?? $this->event->id,
            'status' => 'OPEN',
            'created_at' => $at->copy()->utc(),
            'updated_at' => $at->copy()->utc(),
        ]);

        foreach ($messages as $content) {
            foreach (['VISITOR' => $content, 'AGENT' => 'Je regarde, une inscription se fait en ligne.'] as $author => $text) {
                DB::table('live_chat_messages')->insert([
                    'live_chat_session_id' => $sessionId,
                    'author_type' => $author,
                    'content' => $text,
                    'created_at' => $at->copy()->utc(),
                    'updated_at' => $at->copy()->utc(),
                ]);
            }
        }
    }

    private function refundRequest(string $status): void
    {
        DB::table('live_chat_refund_requests')->insert([
            'organizer_id' => $this->event->organizer_id,
            'event_id' => $this->event->id,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function campaign(string $name, ?string $autoKey, string $status, Carbon $scheduledAt): void
    {
        DB::table('sms_campaigns')->insert([
            'account_id' => $this->event->account_id,
            'organizer_id' => $this->event->organizer_id,
            'event_id' => $this->event->id,
            'name' => $name,
            'message' => 'Rappel',
            'type' => 'REMINDER',
            'status' => $status,
            'scheduled_at' => $scheduledAt->copy()->utc(),
            'auto_key' => $autoKey,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
