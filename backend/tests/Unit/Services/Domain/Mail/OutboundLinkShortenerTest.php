<?php

namespace Tests\Unit\Services\Domain\Mail;

use HiEvents\Services\Domain\Mail\OutboundLinkShortener;
use Tests\TestCase;

class OutboundLinkShortenerTest extends TestCase
{
    private const HOSTS = ['ticket.picha.fr', 'mayottela1ere.fr'];

    private function shorten(): callable
    {
        return static fn (string $url): string => 'https://mayottela1ere.fr/q/'.substr(md5($url), 0, 7);
    }

    public function test_long_links_in_text_become_short_links(): void
    {
        $body = "Voir mon billet: https://mayottela1ere.fr/product/14/a_AD72w9Ryh6Xgj\nGérer ma venue : https://ticket.picha.fr/venue/14/a_X?code=abc.";

        $result = (new OutboundLinkShortener)->rewrite($body, self::HOSTS, $this->shorten(), false);

        $this->assertStringNotContainsString('/product/14/', $result);
        $this->assertStringNotContainsString('/venue/14/', $result);
        $this->assertSame(2, preg_match_all('~https://mayottela1ere\.fr/q/[a-f0-9]{7}~', $result));
        $this->assertStringEndsWith('.', $result);
    }

    public function test_html_links_and_their_visible_text_are_shortened_but_images_are_kept(): void
    {
        $body = '<img src="https://mayottela1ere.fr/api/storage/event_cover/a.webp">'
            .'<img src="https://ticket.picha.fr/order/14/abc/print">'
            .'<a href="https://mayottela1ere.fr/order/14/abc?session=1&amp;x=2">https://mayottela1ere.fr/order/14/abc?session=1&amp;x=2</a>';

        $seen = [];
        $result = (new OutboundLinkShortener)->rewrite($body, self::HOSTS, function (string $url) use (&$seen) {
            $seen[] = $url;

            return 'https://mayottela1ere.fr/q/abcdefg';
        }, true);

        $this->assertSame(['https://mayottela1ere.fr/order/14/abc?session=1&x=2'], $seen);
        $this->assertStringContainsString('src="https://mayottela1ere.fr/api/storage/event_cover/a.webp"', $result);
        $this->assertStringContainsString('src="https://ticket.picha.fr/order/14/abc/print"', $result);
        $this->assertStringContainsString('<a href="https://mayottela1ere.fr/q/abcdefg">https://mayottela1ere.fr/q/abcdefg</a>', $result);
    }

    public function test_other_sites_short_links_and_home_pages_are_left_alone(): void
    {
        $body = 'https://www.facebook.com/djinnocent976/posts/123 https://mayottela1ere.fr/q/abc1234 https://mayottela1ere.fr/ https://ticket.picha.fr';

        $result = (new OutboundLinkShortener)->rewrite($body, self::HOSTS, static fn () => 'SHORT', false);

        $this->assertSame($body, $result);
    }

    public function test_when_the_short_link_cannot_be_made_the_long_link_is_kept(): void
    {
        $body = 'https://mayottela1ere.fr/product/14/a_AD72w9Ryh6Xgj';

        $result = (new OutboundLinkShortener)->rewrite($body, self::HOSTS, static fn (string $url) => $url, false);

        $this->assertSame($body, $result);
    }
}
