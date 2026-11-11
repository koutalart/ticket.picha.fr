<?php

namespace Tests\Unit\Services\Domain\Sms;

use HiEvents\Services\Domain\Mail\OutboundLinkShortener;
use HiEvents\Services\Domain\PublicUrl\PublicBaseUrlResolver;
use HiEvents\Services\Domain\Sms\SmsOutboundLinkShortener;
use HiEvents\Services\Domain\Sms\SmsShortLinkService;
use Mockery;
use Psr\Log\NullLogger;
use Tests\TestCase;

class SmsOutboundLinkShortenerTest extends TestCase
{
    public function test_an_sms_never_leaves_with_a_long_link_to_our_sites(): void
    {
        $resolver = Mockery::mock(PublicBaseUrlResolver::class);
        $resolver->shouldReceive('platformUrl')->andReturn('https://ticket.picha.fr');
        $resolver->shouldReceive('forOrganizerId')->with(4)->andReturn('https://innocent976.yt');

        $shortLinks = Mockery::mock(SmsShortLinkService::class);
        $shortLinks->shouldReceive('shortenFor')
            ->with(2, 4, null, '', 'https://innocent976.yt/event/7/halloween?qr=abc')
            ->once()
            ->andReturn('https://innocent976.yt/q/h4ll0w3');

        $service = new SmsOutboundLinkShortener(new OutboundLinkShortener, $shortLinks, $resolver, new NullLogger);

        $this->assertSame(
            'Ton billet ici : https://innocent976.yt/q/h4ll0w3',
            $service->shorten(2, 4, 'Ton billet ici : https://innocent976.yt/event/7/halloween?qr=abc'),
        );
        $this->assertSame('Merci, à samedi !', $service->shorten(2, 4, 'Merci, à samedi !'));
    }
}
