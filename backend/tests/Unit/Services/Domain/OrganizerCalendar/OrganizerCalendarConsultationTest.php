<?php

namespace Tests\Unit\Services\Domain\OrganizerCalendar;

use HiEvents\DomainObjects\SmsMessageDomainObject;
use HiEvents\Helper\UserAgentSummarizer;
use HiEvents\Models\Event;
use HiEvents\Models\OrganizerCalendar;
use HiEvents\Models\OrganizerCalendarAccess;
use HiEvents\Models\OrganizerCalendarCode;
use HiEvents\Models\OrganizerCalendarConsultation;
use HiEvents\Services\Domain\OrganizerCalendar\OrganizerCalendarConsultationService;
use HiEvents\Services\Domain\Sms\DTO\SendSmsDTO;
use HiEvents\Services\Domain\Sms\SmsSendService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

class OrganizerCalendarConsultationTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    private const SLUG = 'test-prenom';

    private const DEVICE = '3f2b9c1e-7a4d-4e8b-9c2a-1d5e6f7a8b9c';

    private const IPHONE_INSTAGRAM = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Instagram 350.0';

    private Event $event;

    private OrganizerCalendar $calendar;

    /** @var SendSmsDTO[] */
    private array $sentSms = [];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-24 15:00:00', 'UTC'));
        config(['app.customer_locale' => 'fr']);

        [$this->event] = $this->createEventWithProduct();
        $this->calendar = $this->makeCalendar(self::SLUG);

        $this->mock(SmsSendService::class, function ($mock): void {
            $mock->shouldReceive('send')->andReturnUsing(function (SendSmsDTO $sms): SmsMessageDomainObject {
                $this->sentSms[] = $sms;

                return new SmsMessageDomainObject;
            });
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_new_calendars_open_by_first_name_by_default(): void
    {
        self::assertSame('name', $this->calendar->refresh()->access_mode);
    }

    public function test_name_mode_never_sends_an_sms_code(): void
    {
        $this->postJson($this->api('/code'))->assertStatus(404);
        $this->postJson($this->api('/code'))->assertStatus(404);

        self::assertSame([], $this->sentSms);
        self::assertSame(0, OrganizerCalendarCode::query()->count());
    }

    public function test_consulting_grants_the_same_access_as_a_code_and_logs_the_opening(): void
    {
        $response = $this->consult(' <b>Marina</b> ', ['User-Agent' => self::IPHONE_INSTAGRAM, 'REMOTE_ADDR' => '102.35.12.201']);

        $response->assertOk()->assertJsonMissingPath('token')->assertJsonMissingPath('admin');
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $response->json('jeton'));
        self::assertSame(Carbon::now()->addDays(365)->toIso8601String(), $response->json('expire_le'));
        self::assertSame([], $this->sentSms);

        $access = OrganizerCalendarAccess::query()->sole();
        self::assertSame('Marina', $access->visitor_name);
        self::assertSame(self::DEVICE, $access->device_id);

        $consultation = OrganizerCalendarConsultation::query()->sole();
        self::assertSame($this->calendar->id, $consultation->organizer_calendar_id);
        self::assertSame($access->id, $consultation->organizer_calendar_access_id);
        self::assertSame('Marina', $consultation->visitor_name);
        self::assertSame(self::DEVICE, $consultation->device_id);
        self::assertSame('102.35.12.201', $consultation->ip_address);
        self::assertSame(self::IPHONE_INSTAGRAM, $consultation->user_agent);
        self::assertSame('ouverture', $consultation->kind);
        self::assertSame('2026-10-24 15:00:00', Carbon::parse($consultation->created_at)->toDateTimeString());

        $this->getJson($this->api(), ['X-Acces-Calendrier' => $response->json('jeton')])->assertOk();
    }

    public function test_consulting_is_refused_in_code_mode(): void
    {
        $this->artisan('calendar:access-mode', ['slug' => self::SLUG, 'mode' => 'code'])->assertExitCode(0)->run();

        $this->consult('Marina')->assertStatus(404);

        self::assertSame(0, OrganizerCalendarAccess::query()->count());
        self::assertSame(0, OrganizerCalendarConsultation::query()->count());
        $this->postJson($this->api('/code'))->assertOk();
        self::assertCount(1, $this->sentSms);
    }

    public function test_first_name_and_device_are_validated(): void
    {
        $this->postJson($this->api('/consulter'), ['appareil' => self::DEVICE])->assertStatus(422)->assertJsonValidationErrors('prenom');
        $this->consult('   ')->assertStatus(422)->assertJsonValidationErrors('prenom');
        $this->consult('<script></script>')->assertStatus(422)->assertJsonValidationErrors('prenom');
        $this->consult(str_repeat('a', 61))->assertStatus(422)->assertJsonValidationErrors('prenom');
        $this->consult(str_repeat('é', 60))->assertOk();
        Carbon::setTestNow(Carbon::now()->addMinutes(2));

        $this->postJson($this->api('/consulter'), ['prenom' => 'Marina'])->assertStatus(422)->assertJsonValidationErrors('appareil');
        foreach (['court', str_repeat('a', 65), 'abc def ghi', 'abcd/efgh', "abcd\nefgh", 'abcdéfgh'] as $device) {
            $this->postJson($this->api('/consulter'), ['prenom' => 'Marina', 'appareil' => $device])
                ->assertStatus(422)->assertJsonValidationErrors('appareil');
        }

        self::assertSame(1, OrganizerCalendarConsultation::query()->count());
    }

    public function test_consultations_are_limited_per_ip(): void
    {
        for ($i = 0; $i < 30; $i++) {
            if ($i % 10 === 0) {
                Carbon::setTestNow(Carbon::now()->addMinutes(2));
            }
            $this->consult('Marina')->assertOk();
        }

        Carbon::setTestNow(Carbon::now()->addMinutes(2));
        $limited = $this->consult('Marina');
        $limited->assertStatus(429)->assertHeader('Retry-After');
        self::assertStringContainsString('Trop de tentatives', (string) $limited->json('message'));
        self::assertSame(30, OrganizerCalendarConsultation::query()->count());
    }

    public function test_unknown_calendar_answers_404(): void
    {
        $this->postJson('/public/calendriers/inconnu/consulter', ['prenom' => 'Marina', 'appareil' => self::DEVICE])->assertStatus(404);
    }

    public function test_a_visit_is_logged_at_most_every_ten_minutes_per_access(): void
    {
        $key = (string) $this->consult('Marina')->json('jeton');
        $headers = ['X-Acces-Calendrier' => $key, 'User-Agent' => 'Mozilla/5.0 (Linux; Android 14) Chrome/130.0 Mobile Safari/537.36'];

        $this->getJson($this->api(), $headers)->assertOk();
        self::assertSame(0, $this->visits());

        Carbon::setTestNow(Carbon::now()->addMinutes(10)->addSecond());
        $this->getJson($this->api(), $headers)->assertOk();
        $this->getJson($this->api(), $headers)->assertOk();
        self::assertSame(1, $this->visits());

        Carbon::setTestNow(Carbon::now()->addMinutes(9));
        $this->getJson($this->api(), $headers)->assertOk();
        self::assertSame(1, $this->visits());

        Carbon::setTestNow(Carbon::now()->addMinutes(2));
        $this->getJson($this->api(), $headers)->assertOk();
        self::assertSame(2, $this->visits());

        $visit = OrganizerCalendarConsultation::query()->where('kind', 'visite')->latest('id')->first();
        self::assertSame('Marina', $visit->visitor_name);
        self::assertSame(self::DEVICE, $visit->device_id);

        $this->getJson($this->api(), ['X-Acces-Calendrier' => str_repeat('a', 64)])->assertStatus(401);
        self::assertSame(2, $this->visits());
    }

    public function test_admin_code_still_works_in_name_mode_without_any_sms(): void
    {
        self::assertSame(0, Artisan::call('calendar:admin-code', ['slug' => self::SLUG]));
        self::assertSame(1, preg_match('/\b([A-Z2-9]{4}(?:-[A-Z2-9]{4}){4})\b/', Artisan::output(), $matches));

        $response = $this->postJson($this->api('/verifier'), ['code' => $matches[1]]);

        $response->assertOk()->assertJsonPath('admin', true);
        $this->getJson($this->api(), ['X-Acces-Calendrier' => $response->json('jeton')])->assertOk();
        $this->getJson($this->api(), ['X-Acces-Calendrier' => $response->json('jeton')])->assertOk();
        self::assertSame([], $this->sentSms);

        $visit = OrganizerCalendarConsultation::query()->sole();
        self::assertNull($visit->visitor_name);
        self::assertSame('visite', $visit->kind);
    }

    public function test_access_mode_command(): void
    {
        $this->artisan('calendar:access-mode', ['slug' => self::SLUG, 'mode' => 'code'])->assertExitCode(0)->run();
        self::assertSame('code', $this->calendar->refresh()->access_mode);

        $this->artisan('calendar:access-mode', ['slug' => self::SLUG, 'mode' => 'name'])->assertExitCode(0)->run();
        self::assertSame('name', $this->calendar->refresh()->access_mode);

        $this->artisan('calendar:access-mode', ['slug' => self::SLUG, 'mode' => 'sms'])->assertExitCode(1);
        $this->artisan('calendar:access-mode', ['slug' => 'inconnu', 'mode' => 'name'])->assertExitCode(1);
    }

    public function test_consultations_command_lists_in_mayotte_time_with_totals_per_first_name(): void
    {
        $this->consult('Marina', ['User-Agent' => self::IPHONE_INSTAGRAM, 'REMOTE_ADDR' => '102.35.12.201'])->assertOk();
        Carbon::setTestNow(Carbon::now()->addMinutes(5));
        $this->withServerVariables(['REMOTE_ADDR' => '41.0.0.7'])
            ->postJson($this->api('/consulter'), ['prenom' => 'Innocent', 'appareil' => 'abcd1234ef'])->assertOk();

        $other = $this->makeCalendar('autre-calendrier');
        OrganizerCalendarConsultation::query()->create([
            'organizer_calendar_id' => $other->id,
            'visitor_name' => 'Personne',
            'kind' => 'ouverture',
        ]);

        self::assertSame(0, Artisan::call('calendar:consultations', ['slug' => self::SLUG]));
        $output = Artisan::output();

        self::assertStringContainsString('24/10/2026 18:00:00', $output);
        self::assertStringContainsString('24/10/2026 18:05:00', $output);
        self::assertStringContainsString('Marina', $output);
        self::assertStringContainsString('Innocent', $output);
        self::assertStringContainsString('| 3f2b ', $output);
        self::assertStringNotContainsString(self::DEVICE, $output);
        self::assertStringContainsString('102.35.12.201', $output);
        self::assertStringContainsString('Instagram iPhone', $output);
        self::assertStringContainsString('ouverture', $output);
        self::assertStringContainsString('Total par prénom', $output);
        self::assertStringNotContainsString('Personne', $output);
        self::assertLessThan(strpos($output, 'Marina'), strpos($output, 'Innocent'));

        self::assertSame(0, Artisan::call('calendar:consultations', ['slug' => self::SLUG, '--since' => '2026-10-24 18:03']));
        $since = Artisan::output();
        self::assertStringContainsString('Innocent', $since);
        self::assertStringNotContainsString('Marina', $since);

        self::assertSame(0, Artisan::call('calendar:consultations', ['slug' => self::SLUG, '--limit' => 1]));
        self::assertStringNotContainsString('18:00:00 ', strstr(Artisan::output(), 'Total par prénom', true));

        $this->artisan('calendar:consultations', ['slug' => 'inconnu'])->assertExitCode(1);
        $this->artisan('calendar:consultations', ['slug' => self::SLUG, '--since' => 'pas une date'])->assertExitCode(1);
    }

    public function test_consultations_are_purged_after_180_days_and_the_purge_is_scheduled(): void
    {
        $this->consult('Recent')->assertOk();
        OrganizerCalendarConsultation::query()->create([
            'organizer_calendar_id' => $this->calendar->id,
            'visitor_name' => 'Ancien',
            'kind' => 'ouverture',
            'created_at' => Carbon::now()->subDays(180)->subMinute(),
        ]);
        OrganizerCalendarConsultation::query()->create([
            'organizer_calendar_id' => $this->calendar->id,
            'visitor_name' => 'Limite',
            'kind' => 'visite',
            'created_at' => Carbon::now()->subDays(179),
        ]);

        $this->artisan('calendar:purge-consultations')->assertExitCode(0)->run();

        self::assertEqualsCanonicalizing(['Recent', 'Limite'], OrganizerCalendarConsultation::query()->pluck('visitor_name')->all());
        self::assertSame(180, OrganizerCalendarConsultationService::RETENTION_DAYS);

        Artisan::call('schedule:list');
        self::assertStringContainsString('calendar:purge-consultations', Artisan::output());
    }

    public function test_user_agent_is_truncated_and_summarized(): void
    {
        $this->consult('Marina', ['User-Agent' => str_repeat('x', 800)])->assertOk();

        self::assertSame(500, strlen((string) OrganizerCalendarConsultation::query()->sole()->user_agent));
        self::assertSame('Chrome Android', UserAgentSummarizer::summarize('Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/130.0 Mobile Safari/537.36'));
        self::assertSame('Safari iPhone', UserAgentSummarizer::summarize('Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) Version/18.0 Mobile/15E148 Safari/604.1'));
        self::assertSame('Facebook iPhone', UserAgentSummarizer::summarize('Mozilla/5.0 (iPhone) [FBAN/FBIOS;FBAV/480.0]'));
        self::assertSame('?', UserAgentSummarizer::summarize(null));
    }

    private function consult(string $name, array $headers = []): \Illuminate\Testing\TestResponse
    {
        $server = [];
        foreach ($headers as $header => $value) {
            if ($header === 'REMOTE_ADDR') {
                $server['REMOTE_ADDR'] = $value;
            } else {
                $server['HTTP_'.strtoupper(str_replace('-', '_', $header))] = $value;
            }
        }

        return $this->withServerVariables($server)->postJson($this->api('/consulter'), ['prenom' => $name, 'appareil' => self::DEVICE]);
    }

    private function visits(): int
    {
        return OrganizerCalendarConsultation::query()->where('kind', 'visite')->count();
    }

    private function makeCalendar(string $slug): OrganizerCalendar
    {
        return OrganizerCalendar::query()->create([
            'organizer_id' => $this->event->organizer_id,
            'slug' => $slug,
            'title' => 'Calendrier',
            'phone' => '+262639250206',
            'content' => ['jours' => []],
        ]);
    }

    private function api(string $suffix = ''): string
    {
        return '/public/calendriers/'.self::SLUG.$suffix;
    }
}
