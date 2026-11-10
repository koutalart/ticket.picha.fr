<?php

namespace Tests\Unit\Services\Domain\Sms;

use HiEvents\DomainObjects\Enums\QrLinkVisitType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Models\Event;
use HiEvents\Models\Organizer;
use HiEvents\Models\QrLink;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Services\Application\Handlers\QrLink\GetQrLinksHandler;
use HiEvents\Services\Application\Handlers\QrLink\RecordQrLinkVisitHandler;
use HiEvents\Services\Domain\QrLink\DTO\QrLinkVisitContextDTO;
use HiEvents\Services\Domain\Sms\SmsShortLinkService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

class SmsShortLinkServiceTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    private const LONG_URL = 'https://mayottela1ere.fr/venue/14/a_7Kp2QxR9mWvLz?code=3f9c2a7e1b8d';

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.frontend_url' => 'https://ticket.picha.fr']);
        [$this->event] = $this->createEventWithProduct();
        Organizer::query()->whereKey($this->event->organizer_id)->update(['custom_domain' => 'mayottela1ere.fr']);
    }

    public function test_the_short_link_is_a_tracked_q_link_on_the_organizer_domain_that_redirects_to_the_long_link(): void
    {
        $shortUrl = app(SmsShortLinkService::class)->shorten($this->eventDomainObject(), self::LONG_URL);

        $this->assertMatchesRegularExpression('~^https://mayottela1ere\.fr/q/[a-z0-9]{7}$~', $shortUrl);

        $qrLink = QrLink::query()->where('short_id', basename($shortUrl))->sole();
        $this->assertSame($this->event->organizer_id, $qrLink->organizer_id);
        $this->assertSame($this->event->account_id, $qrLink->account_id);
        $this->assertSame($this->event->id, $qrLink->event_id);
        $this->assertSame('URL', $qrLink->destination_type);
        $this->assertSame(self::LONG_URL, $qrLink->destination_url);
        $this->assertTrue((bool) $qrLink->is_automatic_sms_link);

        $visit = app(RecordQrLinkVisitHandler::class)->handle(basename($shortUrl), new QrLinkVisitContextDTO(
            type: QrLinkVisitType::SCAN,
            ipAddress: '102.16.0.1',
            userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148',
        ));

        $this->assertSame(self::LONG_URL, $visit->redirect_url);
        $this->assertTrue($visit->recorded);
        $this->assertSame(1, QrLink::query()->find($qrLink->id)->scans_count);
    }

    public function test_the_same_long_link_reuses_the_same_short_link(): void
    {
        $service = app(SmsShortLinkService::class);

        $first = $service->shorten($this->eventDomainObject(), self::LONG_URL);
        $second = $service->shorten($this->eventDomainObject(), self::LONG_URL);
        $other = $service->shorten($this->eventDomainObject(), self::LONG_URL.'0');

        $this->assertSame($first, $second);
        $this->assertNotSame($first, $other);
        $this->assertSame(2, QrLink::query()->where('organizer_id', $this->event->organizer_id)->count());
    }

    public function test_automatic_links_stay_out_of_the_organizer_qr_code_list(): void
    {
        app(SmsShortLinkService::class)->shorten($this->eventDomainObject(), self::LONG_URL);

        $this->assertCount(0, app(GetQrLinksHandler::class)->handle($this->event->organizer_id));
    }

    private function eventDomainObject(): EventDomainObject
    {
        return app(EventRepositoryInterface::class)->findById($this->event->id);
    }
}
