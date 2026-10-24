<?php

namespace Tests\Unit\Services\Domain\OrganizerCalendar;

use HiEvents\DomainObjects\Enums\SmsMessageAuthorType;
use HiEvents\DomainObjects\SmsMessageDomainObject;
use HiEvents\Exceptions\SmsGatewayException;
use HiEvents\Models\Event;
use HiEvents\Models\OrganizerCalendar;
use HiEvents\Models\OrganizerCalendarAccess;
use HiEvents\Models\OrganizerCalendarCode;
use HiEvents\Models\OrganizerCalendarMedia;
use HiEvents\Services\Domain\OrganizerCalendar\OrganizerCalendarCodeService;
use HiEvents\Services\Domain\Sms\DTO\SendSmsDTO;
use HiEvents\Services\Domain\Sms\SmsSendService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

class OrganizerCalendarTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    private const SLUG = 'test-halloween';

    private const PHONE = '+262639250206';

    private const DISK = 'calendar-test';

    private Event $event;

    private string $fixture;

    /** @var SendSmsDTO[] */
    private array $sentSms = [];

    private bool $smsFails = false;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-23 12:00:00', 'UTC'));
        config(['filesystems.private' => self::DISK, 'app.api_url' => 'http://localhost', 'app.customer_locale' => 'fr']);
        Storage::fake(self::DISK);

        [$this->event] = $this->createEventWithProduct();
        $this->fixture = $this->makeFixture();

        $this->mock(SmsSendService::class, function ($mock): void {
            $mock->shouldReceive('send')->andReturnUsing(function (SendSmsDTO $sms): SmsMessageDomainObject {
                if ($this->smsFails) {
                    throw new SmsGatewayException('gateway down');
                }
                $this->sentSms[] = $sms;

                return new SmsMessageDomainObject;
            });
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        if (isset($this->fixture) && is_dir($this->fixture)) {
            $this->removeDirectory($this->fixture);
        }

        parent::tearDown();
    }

    public function test_import_stores_media_privately_and_rewrites_only_media_keys(): void
    {
        $this->import()->assertExitCode(0);

        $calendar = OrganizerCalendar::query()->where('slug', self::SLUG)->firstOrFail();
        self::assertSame($this->event->organizer_id, $calendar->organizer_id);
        self::assertSame(self::PHONE, $calendar->phone);
        self::assertStringContainsString('<html', (string) $calendar->front_html);

        $media = OrganizerCalendarMedia::query()->where('organizer_calendar_id', $calendar->id)->get();
        self::assertCount(3, $media);
        foreach ($media as $item) {
            Storage::disk(self::DISK)->assertExists($item->path);
            self::assertStringStartsWith('organizer-calendars/'.$calendar->id.'/', $item->path);
        }

        $publication = $calendar->content['jours'][0]['p'][0];
        self::assertMatchesRegularExpression('/^media:\d+$/', $publication['vignette']);
        self::assertCount(2, $publication['media']);
        self::assertMatchesRegularExpression('/^media:\d+$/', $publication['media'][1]);
        self::assertSame('photo.jpg', $publication['t'], 'Values outside the media keys are never rewritten');
        self::assertSame('https://example.test/remote.jpg', $calendar->content['jours'][0]['p'][1]['vignette']);
    }

    public function test_reimport_replaces_media_and_deletes_old_files(): void
    {
        $this->import()->assertExitCode(0);
        $oldPaths = OrganizerCalendarMedia::query()->pluck('path')->all();

        file_put_contents($this->fixture.'/photo.jpg', 'new-jpeg-bytes');
        $this->import()->assertExitCode(0);

        self::assertSame(1, OrganizerCalendar::query()->where('slug', self::SLUG)->count());
        $newPaths = OrganizerCalendarMedia::query()->pluck('path')->all();
        self::assertCount(3, $newPaths);
        foreach ($oldPaths as $path) {
            Storage::disk(self::DISK)->assertMissing($path);
        }
        self::assertEmpty(array_intersect($oldPaths, $newPaths));
    }

    public function test_import_fails_when_a_named_media_is_missing_unless_allowed(): void
    {
        unlink($this->fixture.'/clip.mp4');

        $this->import()->assertExitCode(1);
        self::assertSame(0, OrganizerCalendar::query()->where('slug', self::SLUG)->count());

        $this->import(['--allow-missing' => true])->assertExitCode(0);
        $calendar = OrganizerCalendar::query()->where('slug', self::SLUG)->firstOrFail();
        self::assertCount(1, $calendar->content['jours'][0]['p'][0]['media']);
    }

    public function test_import_rejects_path_traversal_and_foreign_slug(): void
    {
        $content = json_decode(file_get_contents($this->fixture.'/calendar.json'), true);
        $content['jours'][0]['p'][0]['vignette'] = '../secret.jpg';
        file_put_contents($this->fixture.'/calendar.json', json_encode($content));
        $this->import()->assertExitCode(1);

        OrganizerCalendar::query()->create([
            'organizer_id' => $this->event->organizer_id,
            'slug' => 'autre',
            'title' => 'Autre',
            'phone' => self::PHONE,
            'content' => [],
        ]);
        [$otherEvent] = $this->createEventWithProduct();
        $this->artisan('calendar:import', [
            'organizer' => $otherEvent->organizer_id,
            'slug' => 'autre',
            'dir' => $this->fixture,
            '--phone' => self::PHONE,
        ])->assertExitCode(1);
    }

    public function test_requesting_a_code_sends_an_automatic_sms_and_stores_only_a_hash(): void
    {
        $this->importInCodeMode();

        $response = $this->postJson($this->api('/code'));

        $response->assertOk()->assertJson(['telephone_masque' => '******06', 'expire_dans' => 900]);
        self::assertCount(1, $this->sentSms);
        self::assertSame(self::PHONE, $this->sentSms[0]->phone);
        self::assertSame($this->event->organizer_id, $this->sentSms[0]->organizerId);
        self::assertSame(SmsMessageAuthorType::AUTOMATIC, $this->sentSms[0]->authorType);

        $code = $this->lastCode();
        $stored = OrganizerCalendarCode::query()->firstOrFail();
        self::assertNotSame($code, $stored->code_hash);
        self::assertSame(64, strlen($stored->code_hash));
        self::assertSame('2026-10-23 12:15:00', Carbon::parse($stored->expires_at)->toDateTimeString());
    }

    public function test_one_code_every_five_minutes_and_five_per_day(): void
    {
        $this->importInCodeMode();

        $this->postJson($this->api('/code'))->assertOk();
        $limited = $this->postJson($this->api('/code'));
        $limited->assertStatus(429)->assertJsonPath('retry_after', 300)->assertHeader('Retry-After', '300');
        self::assertStringContainsString('réessayez dans 5 min', $limited->json('message'));

        for ($i = 0; $i < 4; $i++) {
            Carbon::setTestNow(Carbon::now()->addMinutes(6));
            $this->postJson($this->api('/code'))->assertOk();
        }

        Carbon::setTestNow(Carbon::now()->addMinutes(6));
        $daily = $this->postJson($this->api('/code'));
        $daily->assertStatus(429);
        self::assertStringContainsString("aujourd'hui", $daily->json('message'));
        self::assertSame(8 * 3600 + 30 * 60, $daily->json('retry_after'));
        self::assertCount(5, $this->sentSms);

        Carbon::setTestNow(Carbon::parse('2026-10-23 21:00:00', 'UTC'));
        $this->postJson($this->api('/code'))->assertOk();
        self::assertCount(6, $this->sentSms);
    }

    public function test_codes_that_opened_the_calendar_do_not_count_against_the_daily_limit(): void
    {
        $this->importInCodeMode();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson($this->api('/code'))->assertOk();
            OrganizerCalendarCode::query()->latest('id')->firstOrFail()->update(['used_at' => Carbon::now()]);
            Carbon::setTestNow(Carbon::now()->addMinutes(6));
        }

        $this->postJson($this->api('/code'))->assertOk();
        self::assertCount(6, $this->sentSms);
    }

    public function test_a_code_that_could_not_be_sent_is_discarded(): void
    {
        $this->importInCodeMode();
        $this->smsFails = true;

        $this->postJson($this->api('/code'))->assertStatus(503);
        self::assertSame(0, OrganizerCalendarCode::query()->count());

        $this->smsFails = false;
        $this->postJson($this->api('/code'))->assertOk();
    }

    public function test_verifying_the_code_returns_a_one_year_jeton_once(): void
    {
        $this->importInCodeMode();
        $this->postJson($this->api('/code'));

        $response = $this->postJson($this->api('/verifier'), ['code' => $this->lastCode()]);

        $response->assertOk()->assertJsonMissingPath('token')->assertJsonMissingPath('data');
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $response->json('jeton'));
        self::assertSame(Carbon::now()->addDays(365)->toIso8601String(), $response->json('expire_le'));
        self::assertSame(1, OrganizerCalendarAccess::query()->count());
        self::assertNotSame($response->json('jeton'), OrganizerCalendarAccess::query()->value('key_hash'));

        $this->postJson($this->api('/verifier'), ['code' => $this->lastCode()])->assertStatus(422);
    }

    public function test_a_code_allows_five_attempts_then_dies(): void
    {
        $this->importInCodeMode();
        $this->postJson($this->api('/code'));
        $code = $this->lastCode();
        $wrong = $code === '000000' ? '111111' : '000000';

        for ($i = 0; $i < OrganizerCalendarCodeService::MAX_ATTEMPTS; $i++) {
            $this->postJson($this->api('/verifier'), ['code' => $wrong])->assertStatus(422);
        }

        $this->postJson($this->api('/verifier'), ['code' => $code])->assertStatus(422);
        self::assertSame(0, OrganizerCalendarAccess::query()->count());
    }

    public function test_an_expired_code_is_refused(): void
    {
        $this->importInCodeMode();
        $this->postJson($this->api('/code'));

        Carbon::setTestNow(Carbon::now()->addMinutes(16));

        $this->postJson($this->api('/verifier'), ['code' => $this->lastCode()])->assertStatus(422);
    }

    public function test_a_new_code_replaces_the_previous_one(): void
    {
        $this->importInCodeMode();
        $this->postJson($this->api('/code'));
        $first = $this->lastCode();

        Carbon::setTestNow(Carbon::now()->addMinutes(6));
        $this->postJson($this->api('/code'));

        if ($first !== $this->lastCode()) {
            $this->postJson($this->api('/verifier'), ['code' => $first])->assertStatus(422);
        }
        $this->postJson($this->api('/verifier'), ['code' => $this->lastCode()])->assertOk();
    }

    public function test_a_field_named_token_is_not_accepted_as_the_code(): void
    {
        $this->importInCodeMode();
        $this->postJson($this->api('/code'));

        $this->postJson($this->api('/verifier'), ['token' => $this->lastCode()])->assertStatus(422);
    }

    public function test_content_requires_a_valid_access(): void
    {
        $this->importInCodeMode();

        $missing = $this->getJson($this->api());
        $missing->assertStatus(401);
        self::assertStringContainsString('Demandez un nouveau code', $missing->json('message'));

        $this->getJson($this->api(), ['X-Acces-Calendrier' => str_repeat('a', 64)])->assertStatus(401);
        $this->getJson($this->api(), ['X-Acces-Calendrier' => 'nimporte'])->assertStatus(401);
    }

    public function test_content_replaces_media_by_signed_urls_and_keeps_the_rest(): void
    {
        $this->importInCodeMode();
        $jeton = $this->grantAccess();

        $response = $this->getJson($this->api(), ['X-Acces-Calendrier' => $jeton]);

        $response->assertOk();
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $publication = $response->json('jours.0.p.0');
        self::assertIsString($publication['vignette']);
        self::assertMatchesRegularExpression('#^/public/calendriers/'.self::SLUG.'/medias/\d+/photo\.jpg\?a=\d+&expires=\d+&signature=[a-f0-9]+$#', $publication['vignette']);
        self::assertIsArray($publication['media']);
        self::assertMatchesRegularExpression('#/clip\.mp4\?#', $publication['media'][1]);
        self::assertSame('photo.jpg', $publication['t']);
        self::assertSame(['Instagram', 'Facebook'], $publication['r']);
        self::assertSame('J-8', $response->json('jours.0.c'));
        self::assertSame('https://example.test/remote.jpg', $response->json('jours.0.p.1.vignette'));
        self::assertSame(Carbon::now()->addDays(365)->toIso8601String(), $response->json('expire_le'));

        parse_str((string) parse_url($publication['vignette'], PHP_URL_QUERY), $query);
        self::assertSame(Carbon::now()->addHours(12)->getTimestamp(), (int) $query['expires']);
    }

    public function test_a_recognised_device_stays_open_and_is_renewed_on_each_visit(): void
    {
        $this->importInCodeMode();
        $jeton = $this->grantAccess();
        $this->app['auth']->forgetGuards();

        Carbon::setTestNow(Carbon::now()->addMonths(11));
        $this->getJson($this->api(), ['X-Acces-Calendrier' => $jeton])->assertOk()
            ->assertJsonPath('expire_le', Carbon::now()->addDays(365)->toIso8601String());

        Carbon::setTestNow(Carbon::now()->addMonths(11));
        $this->getJson($this->api(), ['X-Acces-Calendrier' => $jeton])->assertOk();
        self::assertSame(1, OrganizerCalendarAccess::query()->count());
        self::assertCount(1, $this->sentSms);
    }

    public function test_an_unused_device_is_forgotten_after_a_year(): void
    {
        $this->importInCodeMode();
        $jeton = $this->grantAccess();
        $this->app['auth']->forgetGuards();

        Carbon::setTestNow(Carbon::now()->addYear()->addSecond());

        $this->getJson($this->api(), ['X-Acces-Calendrier' => $jeton])->assertStatus(401);
    }

    public function test_a_revoked_device_must_request_a_new_code(): void
    {
        $this->importInCodeMode();
        $jeton = $this->grantAccess();

        $this->artisan('calendar:revoke', ['slug' => self::SLUG])->assertSuccessful();

        $this->getJson($this->api(), ['X-Acces-Calendrier' => $jeton])->assertStatus(401);
    }

    public function test_media_urls_stream_with_range_support_and_stop_after_revocation(): void
    {
        $this->importInCodeMode();
        $jeton = $this->grantAccess();
        $url = $this->getJson($this->api(), ['X-Acces-Calendrier' => $jeton])->json('jours.0.p.0.media.1');

        $full = $this->get($url);
        $full->assertOk()->assertHeader('Content-Type', 'video/mp4')->assertHeader('Accept-Ranges', 'bytes');
        self::assertStringContainsString('inline', (string) $full->headers->get('Content-Disposition'));

        $partial = $this->get($url, ['Range' => 'bytes=0-9']);
        $partial->assertStatus(206)->assertHeader('Content-Range', 'bytes 0-9/2048')->assertHeader('Content-Length', '10');

        $this->get(str_replace('signature=', 'signature=0', $url))->assertStatus(403);

        $this->artisan('calendar:revoke', ['slug' => self::SLUG])->assertExitCode(0);
        $this->get($url)->assertStatus(401);
        $this->getJson($this->api(), ['X-Acces-Calendrier' => $jeton])->assertStatus(401);
    }

    public function test_media_urls_last_twelve_hours_and_are_signed_again_on_the_next_visit(): void
    {
        $this->importInCodeMode();
        $jeton = $this->grantAccess();
        $url = $this->getJson($this->api(), ['X-Acces-Calendrier' => $jeton])->json('jours.0.p.0.vignette');

        Carbon::setTestNow(Carbon::now()->addHours(12)->addMinute());

        $this->get($url)->assertStatus(403);
        $fresh = $this->getJson($this->api(), ['X-Acces-Calendrier' => $jeton])->json('jours.0.p.0.vignette');
        $this->get($fresh)->assertOk();
    }

    public function test_page_is_served_and_unknown_slugs_answer_404(): void
    {
        $this->importInCodeMode();

        $page = $this->get('/public/calendriers/'.self::SLUG.'/page');
        $page->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        self::assertStringContainsString('<html', $page->getContent());
        self::assertStringStartsWith('text/html', (string) $page->headers->get('Content-Type'));

        $this->postJson('/public/calendriers/inconnu/code')->assertStatus(404);
        $this->postJson('/public/calendriers/inconnu/verifier', ['code' => '123456'])->assertStatus(404);
        $this->getJson('/public/calendriers/inconnu')->assertStatus(404);
        $this->get('/public/calendriers/inconnu/page')->assertStatus(404);
        self::assertSame([], $this->sentSms);
    }

    public function test_delay_formatting(): void
    {
        self::assertSame('1 min', OrganizerCalendarCodeService::formatDelay(10));
        self::assertSame('5 min', OrganizerCalendarCodeService::formatDelay(300));
        self::assertSame('2 h', OrganizerCalendarCodeService::formatDelay(7200));
        self::assertSame('23 h 30 min', OrganizerCalendarCodeService::formatDelay(84600));
    }

    private function grantAccess(): string
    {
        $this->postJson($this->api('/code'))->assertOk();

        return (string) $this->postJson($this->api('/verifier'), ['code' => $this->lastCode()])->assertOk()->json('jeton');
    }

    private function lastCode(): string
    {
        $sms = end($this->sentSms);
        self::assertNotFalse($sms);
        self::assertMatchesRegularExpression('/^\d{6} est votre code d\'accès au calendrier « Halloween »\. Valable 15 minutes\. Ne le partagez pas\.$/u', $sms->content);

        return substr($sms->content, 0, 6);
    }

    private function api(string $suffix = ''): string
    {
        return '/public/calendriers/'.self::SLUG.$suffix;
    }

    private function importInCodeMode(): void
    {
        $this->import()->assertExitCode(0)->run();
        $this->artisan('calendar:access-mode', ['slug' => self::SLUG, 'mode' => 'code'])->assertExitCode(0)->run();
    }

    private function import(array $options = []): mixed
    {
        return $this->artisan('calendar:import', array_merge([
            'organizer' => $this->event->organizer_id,
            'slug' => self::SLUG,
            'dir' => $this->fixture,
            '--title' => 'Halloween',
            '--phone' => '+262 639 25 02 06',
        ], $options));
    }

    private function makeFixture(): string
    {
        $directory = sys_get_temp_dir().'/calendar-fixture-'.bin2hex(random_bytes(6));
        mkdir($directory.'/videos', 0777, true);

        file_put_contents($directory.'/calendar.json', json_encode([
            'slug' => self::SLUG,
            'jours' => [[
                'c' => 'J-8',
                'd' => 'Vendredi 23 octobre',
                'o' => 'Objectif',
                'p' => [
                    [
                        'h' => 'Publié',
                        'r' => ['Instagram', 'Facebook'],
                        'f' => 'reel',
                        's' => 'publie',
                        't' => 'photo.jpg',
                        'vignette' => 'photo.jpg',
                        'media' => ['./photo.jpg', 'clip.mp4'],
                    ],
                    [
                        'h' => '8h',
                        'r' => ['Brevo'],
                        'f' => 'email',
                        'vignette' => 'https://example.test/remote.jpg',
                        'media' => ['videos/story.mp4'],
                    ],
                ],
            ]],
        ], JSON_UNESCAPED_UNICODE));
        file_put_contents($directory.'/index.html', '<!doctype html><html><body>Calendrier</body></html>');
        file_put_contents($directory.'/photo.jpg', 'jpeg-bytes');
        file_put_contents($directory.'/clip.mp4', str_repeat('v', 2048));
        file_put_contents($directory.'/videos/story.mp4', 'story-bytes');
        file_put_contents($directory.'/unused.png', 'png');

        return $directory;
    }

    private function removeDirectory(string $directory): void
    {
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory.'/'.$entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($directory);
    }
}
