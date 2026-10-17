<?php

namespace Tests\Unit\Helper;

use HiEvents\Helper\Url;
use Tests\TestCase;

class UrlCdnTest extends TestCase
{
    public function test_uses_the_cdn_url_from_config_so_it_survives_config_cache(): void
    {
        config(['app.cdn_url_override' => 'https://ticket.picha.fr/api']);

        $this->assertSame('https://ticket.picha.fr/api/event_cover/a.webp', Url::getCdnUrl('event_cover/a.webp'));
    }

    public function test_falls_back_to_the_storage_disk_without_cdn_url(): void
    {
        config(['app.cdn_url_override' => null, 'filesystems.public' => 'public']);

        $this->assertStringEndsWith('/event_cover/a.webp', Url::getCdnUrl('event_cover/a.webp'));
    }
}
