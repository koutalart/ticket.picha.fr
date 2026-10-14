<?php

namespace Tests\Unit\Services\Domain\QrLink;

use HiEvents\Services\Domain\QrLink\UserAgentParser;
use Tests\TestCase;

class UserAgentParserTest extends TestCase
{
    private UserAgentParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new UserAgentParser;
    }

    public function test_link_preview_crawlers_are_bots(): void
    {
        foreach ([
            'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)',
            'WhatsApp/2.23.20.0',
            'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
            'curl/8.4.0',
            '',
            null,
        ] as $userAgent) {
            $this->assertTrue($this->parser->parse($userAgent)->isBot, (string) $userAgent);
        }
    }

    public function test_iphone_instagram_in_app_browser(): void
    {
        $result = $this->parser->parse('Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Instagram 330.0.0.40.92');

        $this->assertFalse($result->isBot);
        $this->assertSame('mobile', $result->deviceType);
        $this->assertSame('iOS', $result->os);
        $this->assertSame('Instagram', $result->browser);
    }

    public function test_android_chrome(): void
    {
        $result = $this->parser->parse('Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Mobile Safari/537.36');

        $this->assertSame('mobile', $result->deviceType);
        $this->assertSame('Android', $result->os);
        $this->assertSame('Chrome', $result->browser);
    }

    public function test_desktop_safari_and_ipad(): void
    {
        $desktop = $this->parser->parse('Mozilla/5.0 (Macintosh; Intel Mac OS X 14_4) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15');
        $this->assertSame('desktop', $desktop->deviceType);
        $this->assertSame('macOS', $desktop->os);
        $this->assertSame('Safari', $desktop->browser);

        $ipad = $this->parser->parse('Mozilla/5.0 (iPad; CPU OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1');
        $this->assertSame('tablet', $ipad->deviceType);
        $this->assertSame('iOS', $ipad->os);
    }
}
