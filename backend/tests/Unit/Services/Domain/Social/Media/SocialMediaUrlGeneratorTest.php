<?php

namespace Tests\Unit\Services\Domain\Social\Media;

use Carbon\Carbon;
use HiEvents\DomainObjects\SocialMediaFileDomainObject;
use HiEvents\Services\Domain\Social\Media\SocialMediaUrlGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class SocialMediaUrlGeneratorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.api_url' => 'https://ticket.example.test/api']);
    }

    public function test_public_url_is_signed_ends_with_the_extension_and_lasts_48_hours(): void
    {
        $file = (new SocialMediaFileDomainObject)->setId(42)->setMimeType('video/mp4')->setOrganizerId(4);
        $url = app(SocialMediaUrlGenerator::class)->publicUrl($file, Carbon::now()->addHours(SocialMediaUrlGenerator::PUBLISHING_VALIDITY_HOURS));

        $this->assertStringStartsWith('https://ticket.example.test/api/public/social/media/42/media-42.mp4?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertGreaterThanOrEqual(Carbon::now()->addHours(47)->getTimestamp(), (int) $query['expires']);
        $this->assertNotEmpty($query['signature']);

        $relative = substr($url, strlen('https://ticket.example.test/api'));
        $this->assertTrue(URL::hasValidSignature(Request::create('http://backend:8080'.$relative), absolute: false));
        $this->assertFalse(URL::hasValidSignature(Request::create('http://backend:8080'.str_replace('media/42/', 'media/43/', $relative)), absolute: false));
    }

    public function test_preview_url_goes_through_the_authenticated_api(): void
    {
        $this->assertSame(
            'https://ticket.example.test/api/organizers/4/social/media/42/content',
            app(SocialMediaUrlGenerator::class)->previewUrl(4, 42),
        );
    }
}
