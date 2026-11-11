<?php

namespace Tests\Unit\Services\Domain\Sms\Waitlist;

use HiEvents\DomainObjects\Enums\CustomerAddressForm;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\ProductPriceDomainObject;
use HiEvents\DomainObjects\SmsMessageDomainObject;
use HiEvents\DomainObjects\Status\WaitlistEntryStatus;
use HiEvents\DomainObjects\WaitlistEntryDomainObject;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductPriceRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Repository\Interfaces\WaitlistEntryRepositoryInterface;
use HiEvents\Services\Domain\Customer\CustomerLocaleResolver;
use HiEvents\Services\Domain\PublicUrl\PublicBaseUrlResolver;
use HiEvents\Services\Domain\Sms\DTO\SendSmsDTO;
use HiEvents\Services\Domain\Sms\Reminder\SmsReminderMessageRenderer;
use HiEvents\Services\Domain\Sms\SmsShortLinkService;
use HiEvents\Services\Domain\Sms\SmsWithLinkSender;
use HiEvents\Services\Domain\Sms\Waitlist\SmsWaitlistService;
use Mockery as m;
use Tests\TestCase;

class SmsWaitlistServiceTest extends TestCase
{
    private SmsWithLinkSender $sender;

    private SmsWaitlistService $service;

    private ?string $shortenedUrl = null;

    protected function setUp(): void
    {
        parent::setUp();

        $urls = m::mock(PublicBaseUrlResolver::class);
        $urls->shouldReceive('forEvent', 'forOrganizer', 'forOrganizerId')->andReturn('https://mayottela1ere.fr');
        $this->app->instance(PublicBaseUrlResolver::class, $urls);

        $event = (new EventDomainObject)->setId(14)->setOrganizerId(8)->setTitle('Concert des Générations')
            ->setTimezone('Indian/Mayotte')->setStartDate('2026-11-14 14:30:00');
        $events = m::mock(EventRepositoryInterface::class);
        $events->shouldReceive('loadRelation')->andReturnSelf();
        $events->shouldReceive('findFirst')->andReturn($event);

        $prices = m::mock(ProductPriceRepositoryInterface::class);
        $prices->shouldReceive('findFirst')->andReturn((new ProductPriceDomainObject)->setId(30)->setProductId(27));
        $products = m::mock(ProductRepositoryInterface::class);
        $products->shouldReceive('findFirst')->andReturn((new ProductDomainObject)->setId(27)->setTitle('Invitation pour 2 personnes'));

        $shortLinks = m::mock(SmsShortLinkService::class);
        $shortLinks->shouldReceive('shorten')->andReturnUsing(function ($event, string $url) {
            $this->shortenedUrl = $url;

            return 'https://mayottela1ere.fr/k2m9x4p';
        });

        $locale = m::mock(CustomerLocaleResolver::class);
        $locale->shouldReceive('addressFormForOrganizerId')->andReturn(CustomerAddressForm::VOUS);

        $this->sender = m::mock(SmsWithLinkSender::class);

        $this->service = new SmsWaitlistService(
            m::mock(WaitlistEntryRepositoryInterface::class), $events, $prices, $products,
            new SmsReminderMessageRenderer, $shortLinks, $this->sender, $locale,
        );
    }

    private function entry(string $status, ?string $phone = '0639123456', ?string $expiresAt = null): WaitlistEntryDomainObject
    {
        return (new WaitlistEntryDomainObject)->setId(1)->setEventId(14)->setProductPriceId(30)
            ->setFirstName('Awa')->setEmail('awa@example.com')->setStatus($status)
            ->setPhone($phone)->setOfferExpiresAt($expiresAt);
    }

    public function test_confirmation_names_the_ticket_and_sends_the_link_apart(): void
    {
        $this->sender->shouldReceive('send')->once()->withArgs(fn (SendSmsDTO $sms, ?string $link) => $sms->organizerId === 8
            && str_contains($sms->content, 'Invitation pour 2 personnes')
            && str_contains($sms->content, 'Concert des Générations')
            && ! str_contains($sms->content, 'https://')
            && ! str_contains($sms->content, '{billet}')
            && $link === 'https://mayottela1ere.fr/k2m9x4p')->andReturn(new SmsMessageDomainObject);

        $this->assertTrue($this->service->sendConfirmation($this->entry(WaitlistEntryStatus::WAITING->name)));
    }

    public function test_offer_links_to_the_reserved_checkout(): void
    {
        $this->sender->shouldReceive('send')->once()->andReturn(new SmsMessageDomainObject);

        $this->assertTrue($this->service->sendOffer(
            $this->entry(WaitlistEntryStatus::OFFERED->name, expiresAt: now()->addHour()->toDateTimeString()),
            'o_abc',
            'sess',
        ));
        $this->assertStringContainsString('/checkout/14/o_abc/details', (string) $this->shortenedUrl);
        $this->assertStringContainsString('session_identifier=sess', (string) $this->shortenedUrl);
    }

    public function test_nothing_sent_for_an_expired_offer_or_without_phone(): void
    {
        $this->sender->shouldNotReceive('send');

        $this->assertFalse($this->service->sendOffer(
            $this->entry(WaitlistEntryStatus::OFFERED->name, expiresAt: now()->subMinute()->toDateTimeString()), 'o_abc', 'sess',
        ));
        $this->assertFalse($this->service->sendConfirmation($this->entry(WaitlistEntryStatus::WAITING->name, phone: null)));
        $this->assertFalse($this->service->sendConfirmation($this->entry(WaitlistEntryStatus::CANCELLED->name)));
    }
}
