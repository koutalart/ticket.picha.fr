<?php

namespace Tests\Unit\Services\Domain\Event;

use HiEvents\Services\Domain\Event\HomepageFooterSettingsSanitizer;
use HiEvents\Services\Infrastructure\HtmlPurifier\HtmlPurifierService;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class HomepageFooterSettingsSanitizerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private HomepageFooterSettingsSanitizer $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();

        $purifier = Mockery::mock(HtmlPurifierService::class);
        $purifier->shouldReceive('purify')->andReturnUsing(
            fn(?string $value) => $value === null ? null : preg_replace('#<script\b[^>]*>.*?</script>#is', '', $value)
        );

        $this->sanitizer = new HomepageFooterSettingsSanitizer($purifier);
    }

    public function test_returns_null_when_nothing_is_given(): void
    {
        $this->assertNull($this->sanitizer->sanitize(null));
        $this->assertNull($this->sanitizer->sanitize('not json'));
    }

    public function test_strips_markup_from_text_fields(): void
    {
        $result = $this->sanitizer->sanitize([
            'newsletter_title' => '<b>Restez informé</b><script>alert(1)</script>',
        ]);

        $this->assertSame('Restez informé', $result['newsletter_title']);
        $this->assertTrue($result['newsletter_enabled']);
    }

    public function test_rejects_unsafe_urls(): void
    {
        $result = $this->sanitizer->sanitize([
            'privacy_policy_url' => 'javascript:alert(1)',
            'terms_url' => 'https://la1ere.francetvinfo.fr/mayotte/cgu',
            'accessibility_url' => '/legal/accessibilite',
            'event_links' => [
                ['label' => 'Programme', 'url' => 'data:text/html,hello'],
                ['label' => "S'inscrire", 'url' => '#tickets'],
            ],
        ]);

        $this->assertNull($result['privacy_policy_url']);
        $this->assertSame('https://la1ere.francetvinfo.fr/mayotte/cgu', $result['terms_url']);
        $this->assertSame('/legal/accessibilite', $result['accessibility_url']);
        $this->assertSame([['label' => "S'inscrire", 'url' => '#tickets']], $result['event_links']);
    }

    public function test_drops_incomplete_links_and_limits_related_links_to_three(): void
    {
        $links = [];
        for ($i = 1; $i <= 5; $i++) {
            $links[] = ['title' => "Lien $i", 'url' => "https://example.com/$i", 'image_url' => null];
        }
        array_unshift($links, ['title' => '', 'url' => 'https://example.com/vide']);
        $links[] = 'pas un tableau';

        $result = $this->sanitizer->sanitize(['related_links' => $links, 'newsletter_enabled' => false]);

        $this->assertCount(3, $result['related_links']);
        $this->assertSame('Lien 1', $result['related_links'][0]['title']);
        $this->assertNull($result['related_links'][0]['image_url']);
        $this->assertFalse($result['newsletter_enabled']);
    }
}
