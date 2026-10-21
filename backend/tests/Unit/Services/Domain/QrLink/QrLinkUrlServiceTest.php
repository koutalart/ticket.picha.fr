<?php

namespace Tests\Unit\Services\Domain\QrLink;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\QrLinkDomainObject;
use HiEvents\Services\Domain\QrLink\QrLinkUrlService;
use HiEvents\Services\Domain\QrLink\UserAgentParser;
use Tests\TestCase;

class QrLinkUrlServiceTest extends TestCase
{
    private const APP_STORE = 'https://apps.apple.com/app/id885421673';

    private const PLAY_STORE = 'https://play.google.com/store/apps/details?id=fr.francetv.outremer';

    public function test_app_link_sends_each_phone_to_its_store(): void
    {
        $service = new QrLinkUrlService;
        $parser = new UserAgentParser;
        $qrLink = $this->appLink('https://la1ere.franceinfo.fr/mayotte/');

        $iphone = $parser->parse('Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1')->os;
        $android = $parser->parse('Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 Chrome/124.0 Mobile Safari/537.36')->os;
        $desktop = $parser->parse('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124.0 Safari/537.36')->os;

        $this->assertSame(self::APP_STORE, $service->getRedirectUrl($qrLink, null, $iphone));
        $this->assertSame(self::PLAY_STORE, $service->getRedirectUrl($qrLink, null, $android));
        $this->assertSame('https://la1ere.franceinfo.fr/mayotte/', $service->getRedirectUrl($qrLink, null, $desktop));
        $this->assertSame('https://la1ere.franceinfo.fr/mayotte/', $service->getRedirectUrl($qrLink, null, null));
    }

    public function test_app_link_without_fallback_uses_the_app_store(): void
    {
        $this->assertSame(self::APP_STORE, (new QrLinkUrlService)->getRedirectUrl($this->appLink(null), null, 'Windows'));
    }

    public function test_event_url_flags_the_visit_as_already_counted(): void
    {
        $event = (new EventDomainObject)->setId(7)->setTitle('Halloween');
        $qrLink = (new QrLinkDomainObject)->setShortId('abcd1234')->setDestinationType('EVENT');

        $url = (new QrLinkUrlService)->getRedirectUrl($qrLink, $event);

        $this->assertStringStartsWith($event->getEventUrl().'?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame(['qr' => 'abcd1234', 'qrc' => '1'], $query);
    }

    private function appLink(?string $fallback): QrLinkDomainObject
    {
        return (new QrLinkDomainObject)
            ->setDestinationType('APP')
            ->setDestinationUrl($fallback)
            ->setAppStoreUrl(self::APP_STORE)
            ->setPlayStoreUrl(self::PLAY_STORE);
    }
}
