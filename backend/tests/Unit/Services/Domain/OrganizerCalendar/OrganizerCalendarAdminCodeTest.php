<?php

namespace Tests\Unit\Services\Domain\OrganizerCalendar;

use HiEvents\DomainObjects\SmsMessageDomainObject;
use HiEvents\Models\Event;
use HiEvents\Models\OrganizerCalendar;
use HiEvents\Models\OrganizerCalendarAccess;
use HiEvents\Models\OrganizerCalendarCode;
use HiEvents\Services\Domain\OrganizerCalendar\OrganizerCalendarAdminCodeService;
use HiEvents\Services\Domain\Sms\DTO\SendSmsDTO;
use HiEvents\Services\Domain\Sms\SmsSendService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

class OrganizerCalendarAdminCodeTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    private const SLUG = 'test-admin';

    private Event $event;

    private OrganizerCalendar $calendar;

    /** @var SendSmsDTO[] */
    private array $sentSms = [];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-24 09:00:00', 'UTC'));
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

    public function test_command_prints_a_long_unambiguous_code_and_stores_only_its_hash(): void
    {
        $code = $this->generateAdminCode();

        self::assertMatchesRegularExpression('/^['.OrganizerCalendarAdminCodeService::ALPHABET.']{4}(-['.OrganizerCalendarAdminCodeService::ALPHABET.']{4}){4}$/', $code);
        self::assertGreaterThanOrEqual(16, strlen(str_replace('-', '', $code)));

        $this->calendar->refresh();
        self::assertSame(64, strlen((string) $this->calendar->admin_code_hash));
        self::assertStringNotContainsString(str_replace('-', '', $code), (string) $this->calendar->admin_code_hash);
        self::assertSame('2026-10-24 09:00:00', Carbon::parse($this->calendar->admin_code_created_at)->toDateTimeString());
    }

    public function test_admin_code_opens_the_calendar_without_any_sms(): void
    {
        $code = $this->generateAdminCode();

        $response = $this->postJson($this->api('/verifier'), ['code' => $code]);

        $response->assertOk()->assertJsonPath('admin', true)->assertJsonMissingPath('token');
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $response->json('jeton'));
        self::assertSame(Carbon::now()->addHours(12)->toIso8601String(), $response->json('expire_le'));
        self::assertSame([], $this->sentSms);
        self::assertSame(0, OrganizerCalendarCode::query()->count());

        $this->getJson($this->api(), ['X-Acces-Calendrier' => $response->json('jeton')])->assertOk();
    }

    public function test_admin_code_is_accepted_without_dashes_and_in_lowercase_and_stays_reusable(): void
    {
        $code = $this->generateAdminCode();

        $this->postJson($this->api('/verifier'), ['code' => strtolower(str_replace('-', '', $code))])->assertOk();
        $this->postJson($this->api('/verifier'), ['code' => ' '.str_replace('-', ' ', $code).' '])->assertOk();
        self::assertSame(2, OrganizerCalendarAccess::query()->count());
    }

    public function test_admin_code_does_not_consume_a_pending_sms_code(): void
    {
        $this->postJson($this->api('/code'))->assertOk();
        $smsCode = substr($this->sentSms[0]->content, 0, 6);

        $this->postJson($this->api('/verifier'), ['code' => $this->generateAdminCode()])->assertOk()->assertJsonPath('admin', true);

        $sms = $this->postJson($this->api('/verifier'), ['code' => $smsCode]);
        $sms->assertOk()->assertJsonMissingPath('admin');
        self::assertCount(1, $this->sentSms);
    }

    public function test_wrong_admin_codes_are_rejected_then_throttled(): void
    {
        $code = $this->generateAdminCode();
        $wrong = $code === 'ABCD-EFGH-JKMN-PQRS-TUVW' ? 'WXYZ-WXYZ-WXYZ-WXYZ-WXYZ' : 'ABCD-EFGH-JKMN-PQRS-TUVW';

        for ($i = 0; $i < 5; $i++) {
            $this->postJson($this->api('/verifier'), ['code' => $wrong])->assertStatus(422);
        }

        $this->postJson($this->api('/verifier'), ['code' => $code])->assertStatus(429)->assertHeader('Retry-After');
        self::assertSame(0, OrganizerCalendarAccess::query()->count());

        Carbon::setTestNow(Carbon::now()->addHour()->addSecond());
        $this->postJson($this->api('/verifier'), ['code' => $code])->assertOk();
    }

    public function test_a_new_admin_code_invalidates_the_previous_one(): void
    {
        $old = $this->generateAdminCode();
        $new = $this->generateAdminCode();

        $this->postJson($this->api('/verifier'), ['code' => $old])->assertStatus(422);
        $this->postJson($this->api('/verifier'), ['code' => $new])->assertOk();
    }

    public function test_revoked_or_missing_admin_code_never_matches(): void
    {
        $this->postJson($this->api('/verifier'), ['code' => 'ABCD-EFGH-JKMN-PQRS-TUVW'])->assertStatus(422);

        $code = $this->generateAdminCode();
        $this->artisan('calendar:admin-code', ['slug' => self::SLUG, '--revoke' => true])->assertExitCode(0);

        self::assertNull($this->calendar->refresh()->admin_code_hash);
        $this->postJson($this->api('/verifier'), ['code' => $code])->assertStatus(422);
    }

    public function test_admin_code_of_another_calendar_is_refused(): void
    {
        $this->makeCalendar('autre-calendrier');
        Artisan::call('calendar:admin-code', ['slug' => 'autre-calendrier']);
        $otherCode = $this->extractCode(Artisan::output());

        $this->postJson($this->api('/verifier'), ['code' => $otherCode])->assertStatus(422);
    }

    public function test_short_values_still_go_through_the_sms_code_path(): void
    {
        self::assertFalse(OrganizerCalendarAdminCodeService::looksLikeAdminCode('123456'));
        self::assertFalse(OrganizerCalendarAdminCodeService::looksLikeAdminCode('ABCD-EFGH-JKM'));
        self::assertTrue(OrganizerCalendarAdminCodeService::looksLikeAdminCode('abcd efgh jkmn pqrs'));
    }

    public function test_unknown_slug_for_the_command_fails(): void
    {
        $this->artisan('calendar:admin-code', ['slug' => 'inconnu'])->assertExitCode(1);
    }

    private function generateAdminCode(): string
    {
        self::assertSame(0, Artisan::call('calendar:admin-code', ['slug' => self::SLUG]));

        return $this->extractCode(Artisan::output());
    }

    private function extractCode(string $output): string
    {
        self::assertSame(1, preg_match('/\b([A-Z2-9]{4}(?:-[A-Z2-9]{4}){4})\b/', $output, $matches), $output);

        return $matches[1];
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
