<?php

namespace Tests\Unit\Services\Domain\Sms\OrganizerAssistant;

use HiEvents\DomainObjects\Enums\OrganizerTeamContactRole;
use HiEvents\Models\Event;
use HiEvents\Models\OrganizerSmsSetting;
use HiEvents\Services\Domain\Event\DailyReport\EventDailyReportService;
use HiEvents\Services\Domain\Sms\OrganizerAssistant\DTO\OrganizerAssistantResultDTO;
use HiEvents\Services\Domain\Sms\OrganizerAssistant\DTO\OrganizerNudgePlanDTO;
use HiEvents\Services\Domain\Sms\OrganizerAssistant\OrganizerAssistantService;
use HiEvents\Services\Domain\Sms\OrganizerAssistant\OrganizerIntroService;
use HiEvents\Services\Domain\Sms\OrganizerAssistant\OrganizerNudgeService;
use HiEvents\Services\Domain\Sms\Team\OrganizerTeamContactService;
use HiEvents\Services\Domain\Sms\Team\SmsDirectSendService;
use HiEvents\Services\Infrastructure\HttpSms\HttpSmsClient;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

class OrganizerNudgeTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    private const TIMEZONE = 'Indian/Mayotte';

    private const LINE_NUMBER = '+262639000000';

    private const RESPONSABLE = '+262639689691';

    private const COPY = '+262639250206';

    private const SUPERVISOR = '+262639780773';

    private Event $event;

    private int $organizerId;

    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelToLocal(Carbon::now(self::TIMEZONE)->setTime(10, 0));
        DB::table('organizer_sms_settings')->update(['api_key' => null, 'shared_line_settings_id' => null]);
        config(['services.platform_supervisor.phones' => self::SUPERVISOR]);

        [$this->event] = $this->createEventWithProduct();
        $this->organizerId = $this->event->organizer_id;
        DB::table('events')->where('id', $this->event->id)->update(['timezone' => self::TIMEZONE]);

        OrganizerSmsSetting::create([
            'account_id' => $this->event->account_id,
            'organizer_id' => $this->organizerId,
            'webhook_token' => Str::random(40),
            'webhook_signing_key' => 'key',
            'api_key' => 'uk_line',
            'from_number' => self::LINE_NUMBER,
        ]);

        $this->mock(HttpSmsClient::class, function (MockInterface $client) {
            $client->shouldReceive('sendMessage')->andReturnUsing(function (string $apiKey, string $from, string $to, string $content) {
                $this->sent[] = compact('from', 'to', 'content');

                return 'provider-'.count($this->sent);
            });
        });

        $this->teamOf(self::RESPONSABLE, OrganizerTeamContactRole::ORGANIZER);
        $this->teamOf(self::COPY, OrganizerTeamContactRole::COPY);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_introduction_and_questions_wait_for_an_answer_but_reports_never_do(): void
    {
        app(OrganizerIntroService::class)->send($this->organizerId, 'Bonjour, je suis Anicka.');
        app(EventDailyReportService::class)->sendNow($this->event->id);

        $this->assertSame(
            [true, false, false],
            DB::table('sms_messages')->orderBy('id')->pluck('awaits_reply')->map(fn ($value) => (bool) $value)->all(),
        );

        $this->assertTrue(OrganizerAssistantService::asksQuestion(new OrganizerAssistantResultDTO("Pour quelle date ?\nAnicka", false, null)));
        $this->assertFalse(OrganizerAssistantService::asksQuestion(new OrganizerAssistantResultDTO("C'est noté.\nAnicka", false, null)));
        $this->assertFalse(OrganizerAssistantService::asksQuestion(new OrganizerAssistantResultDTO("Je regarde ça ?\nAnicka", true, null)));
    }

    public function test_nothing_leaves_while_the_nudges_are_off(): void
    {
        app(OrganizerIntroService::class)->send($this->organizerId, 'Bonjour, je suis Anicka.');
        $this->travelToLocal($this->local()->addDays(4));

        $this->assertSame([], app(OrganizerNudgeService::class)->sendDue());
        $this->assertCount(1, $this->sent);
    }

    public function test_one_nudge_after_72_hours_then_every_72_hours_with_a_new_sentence_until_the_organizer_answers(): void
    {
        $service = $this->enabledService();
        app(OrganizerIntroService::class)->send($this->organizerId, 'Bonjour, je suis Anicka.');
        $start = $this->local();

        $this->travelToLocal($start->copy()->addHours(71)->addMinutes(59));
        $this->assertSame([], $service->sendDue());

        $this->travelToLocal($start->copy()->addHours(72));
        $results = $service->sendDue();
        $this->assertCount(1, $results);
        $this->assertSame(OrganizerNudgePlanDTO::STATE_SENT, $results[0]->state);
        $this->assertSame([self::RESPONSABLE, self::RESPONSABLE], array_column($this->sent, 'to'));
        $this->assertSame("Vous êtes là ? Vous recevez bien mes messages ? 🙂\n\nAnicka", $this->sent[1]['content']);

        $this->assertSame([], $service->sendDue());
        $this->travelToLocal($start->copy()->addHours(100));
        $this->assertSame([], $service->sendDue());

        $this->travelToLocal($start->copy()->addHours(144));
        $this->assertCount(1, $service->sendDue());
        $this->assertCount(3, $this->sent);
        $this->assertNotSame($this->sent[1]['content'], $this->sent[2]['content']);
        $this->assertSame(2, DB::table('organizer_team_nudges')->where('status', 'SENT')->count());

        $this->inbound(self::RESPONSABLE, 'Oui je suis là');
        $this->travelToLocal($start->copy()->addDays(30));
        $this->assertSame([], $service->sendDue());
        $this->assertCount(3, $this->sent);
        $this->assertSame(OrganizerNudgePlanDTO::STATE_ANSWERED, $service->plan($this->organizerId)[0]->state);
    }

    public function test_a_nudge_due_outside_9_to_19_waits_for_9(): void
    {
        $service = $this->enabledService();
        $this->travelToLocal($this->local()->setTime(20, 0));
        app(OrganizerIntroService::class)->send($this->organizerId, 'Bonjour, je suis Anicka.');
        $start = $this->local();

        $this->travelToLocal($start->copy()->addHours(72));
        $this->assertSame([], $service->sendDue());
        $plan = $service->plan($this->organizerId)[0];
        $this->assertSame(OrganizerNudgePlanDTO::STATE_OUTSIDE_HOURS, $plan->state);
        $this->assertSame($start->copy()->addDays(4)->setTime(9, 0)->format('Y-m-d H:i'), $plan->nextNudgeAt);

        $this->travelToLocal($start->copy()->addDays(4)->setTime(8, 59));
        $this->assertSame([], $service->sendDue());

        $this->travelToLocal($start->copy()->addDays(4)->setTime(9, 0));
        $this->assertCount(1, $service->sendDue());

        $this->assertFalse(OrganizerNudgeService::isWithinHours(Carbon::parse('2030-01-01 19:00', self::TIMEZONE)));
        $this->assertTrue(OrganizerNudgeService::isWithinHours(Carbon::parse('2030-01-01 18:59', self::TIMEZONE)));
    }

    public function test_copy_contacts_supervisors_and_opted_out_numbers_are_never_nudged(): void
    {
        $service = $this->enabledService();
        DB::table('organizer_team_contacts')->insert([
            'organizer_id' => $this->organizerId,
            'phone' => self::SUPERVISOR,
            'role' => OrganizerTeamContactRole::ORGANIZER->name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        app(SmsDirectSendService::class)->sendToMany($this->organizerId, [self::RESPONSABLE, self::COPY, self::SUPERVISOR], 'Une question ?', awaitsReply: true);
        DB::table('sms_opt_outs')->insert([
            'organizer_id' => $this->organizerId,
            'phone' => self::RESPONSABLE,
            'source' => 'KEYWORD',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->travelToLocal($this->local()->addDays(3));
        $this->assertSame([], $service->sendDue());
        $this->assertCount(3, $this->sent);

        $states = collect($service->plan($this->organizerId))->pluck('state', 'phone')->all();
        $this->assertSame(OrganizerNudgePlanDTO::STATE_OPTED_OUT, $states[self::RESPONSABLE]);
        $this->assertSame(OrganizerNudgePlanDTO::STATE_SUPERVISOR, $states[self::SUPERVISOR]);
        $this->assertArrayNotHasKey(self::COPY, $states);
    }

    public function test_a_message_already_claimed_by_another_worker_is_not_nudged_twice(): void
    {
        $service = $this->enabledService();
        app(OrganizerIntroService::class)->send($this->organizerId, 'Bonjour, je suis Anicka.');
        $this->travelToLocal($this->local()->addHours(73));

        $plan = $service->plan($this->organizerId)[0];
        DB::table('organizer_team_nudges')->insert([
            'organizer_id' => $this->organizerId,
            'organizer_team_contact_id' => $plan->contactId,
            'awaited_sms_message_id' => $plan->awaitedMessageId,
            'phone' => self::RESPONSABLE,
            'variant' => 0,
            'status' => 'SENDING',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $results = $service->sendDue();
        $this->assertSame(OrganizerNudgePlanDTO::STATE_ALREADY_CLAIMED, $results[0]->state);
        $this->assertCount(1, $this->sent);
    }

    public function test_dry_run_shows_what_would_leave_without_sending_and_the_command_turns_nudges_on(): void
    {
        app(OrganizerIntroService::class)->send($this->organizerId, 'Bonjour, je suis Anicka.');
        $this->travelToLocal($this->local()->addHours(73));

        $this->assertSame(0, Artisan::call('sms:organizer-nudge', ['organizer' => $this->organizerId, '--event' => $this->event->id, '--dry-run' => true]));
        $output = Artisan::output();
        $this->assertStringContainsString('Nudges of organizer '.$this->organizerId.': off. Time zone: Indian/Mayotte.', $output);
        $this->assertStringContainsString(self::RESPONSABLE.'): a nudge is due now', $output);
        $this->assertStringContainsString('Vous êtes là ? Vous recevez bien mes messages ?', $output);
        $this->assertStringContainsString('Dry run: nothing sent.', $output);
        $this->assertCount(1, $this->sent);

        $this->assertSame(0, Artisan::call('sms:organizer-nudge', ['organizer' => $this->organizerId, '--on' => true]));
        $this->assertSame(0, Artisan::call('sms:send-organizer-nudges', ['--dry-run' => true]));
        $this->assertStringContainsString('would be nudged', Artisan::output());
        $this->assertCount(1, $this->sent);

        $this->assertSame(0, Artisan::call('sms:send-organizer-nudges'));
        $this->assertCount(2, $this->sent);

        $this->assertSame(0, Artisan::call('sms:organizer-nudge', ['organizer' => $this->organizerId, '--off' => true]));
        $this->assertFalse((bool) DB::table('organizer_sms_settings')->where('organizer_id', $this->organizerId)->value('organizer_nudge_enabled'));
    }

    public function test_sentences_are_never_repeated_before_all_were_used_then_the_oldest_comes_back(): void
    {
        $variants = OrganizerNudgeService::variants();
        $this->assertSame(count($variants), count(array_unique($variants)));
        foreach ($variants as $index => $variant) {
            $this->assertDoesNotMatchRegularExpression('/[•·—–]/u', OrganizerNudgeService::text($index));
        }

        $this->assertSame(0, OrganizerNudgeService::chooseVariant([]));
        $this->assertSame(2, OrganizerNudgeService::chooseVariant([0, 1]));
        $this->assertSame(3, OrganizerNudgeService::chooseVariant([3, 0, 1, 2, 4, 5, 6, 7]));
        $this->assertSame(1, OrganizerNudgeService::chooseVariant([0, 1, 2, 3, 4, 5, 6, 7, 0]));
    }

    private function enabledService(): OrganizerNudgeService
    {
        $service = app(OrganizerNudgeService::class);
        $service->configure($this->organizerId, enabled: true, eventId: $this->event->id);

        return $service;
    }

    private function teamOf(string $phone, OrganizerTeamContactRole $role): void
    {
        app(OrganizerTeamContactService::class)->save($this->organizerId, $phone, null, $role);
    }

    private function travelToLocal(Carbon $local): void
    {
        Carbon::setTestNow($local->copy()->utc());
    }

    private function local(): Carbon
    {
        return Carbon::now('UTC')->setTimezone(self::TIMEZONE);
    }

    private function inbound(string $phone, string $content): void
    {
        DB::table('sms_messages')->insert([
            'sms_conversation_id' => DB::table('sms_conversations')->where('organizer_id', $this->organizerId)->where('phone', $phone)->value('id'),
            'organizer_id' => $this->organizerId,
            'direction' => 'INBOUND',
            'author_type' => 'CONTACT',
            'content' => $content,
            'status' => 'RECEIVED',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
