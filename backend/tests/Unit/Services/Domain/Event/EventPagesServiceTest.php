<?php

namespace Tests\Unit\Services\Domain\Event;

use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\ImageDomainObject;
use HiEvents\Services\Domain\Event\DTO\EventPagesDTO;
use HiEvents\Services\Domain\Event\EventPagesService;
use HiEvents\Services\Infrastructure\HtmlPurifier\HtmlPurifierService;
use Illuminate\Support\Collection;
use Tests\TestCase;

class EventPagesServiceTest extends TestCase
{
    private EventPagesService $service;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.cdn_url_override' => 'https://cdn.example.com']);
        $this->service = new EventPagesService(app(HtmlPurifierService::class));
    }

    private function pages(array $artists = [], array $practicalInfo = [], array $faq = []): EventPagesDTO
    {
        return EventPagesDTO::from([
            'artists' => $artists,
            'practical_info' => $practicalInfo,
            'faq' => $faq,
        ]);
    }

    public function test_returns_null_when_every_page_is_empty(): void
    {
        $this->assertNull($this->service->normalize($this->pages(
            artists: [['name' => '   ']],
            practicalInfo: [['title' => '', 'content' => '<p>Texte</p>']],
            faq: [['question' => 'Gratuit ?', 'answer' => '<p></p>']],
        ), []));
    }

    public function test_sanitizes_content_and_builds_unique_slugs(): void
    {
        $stored = $this->service->normalize($this->pages(
            artists: [
                ['name' => 'Méli', 'kind' => 'PERSON', 'bio' => '<p>Chanteuse</p><script>alert(1)</script>', 'image_id' => 5],
                ['name' => 'Kemaal & O.B.', 'kind' => 'GROUP', 'image_id' => 99],
                ['name' => 'Méli', 'kind' => 'UNKNOWN', 'links' => [
                    'instagram' => 'https://instagram.com/meli',
                    'facebook' => 'javascript:alert(1)',
                    'myspace' => 'https://myspace.com/meli',
                ]],
            ],
            practicalInfo: [['title' => 'Accès', 'content' => '<p onclick="x()">Mamoudzou</p>']],
            faq: [['question' => 'Est-ce gratuit ?', 'answer' => '<p>Oui, sur inscription.</p>']],
        ), [5]);

        $this->assertSame('meli', $stored['artists'][0]['slug']);
        $this->assertSame('kemaal-ob', $stored['artists'][1]['slug']);
        $this->assertSame('meli-2', $stored['artists'][2]['slug']);

        $this->assertStringNotContainsString('<script', $stored['artists'][0]['bio']);
        $this->assertStringContainsString('Chanteuse', $stored['artists'][0]['bio']);
        $this->assertSame(5, $stored['artists'][0]['image_id']);
        $this->assertNull($stored['artists'][1]['image_id']);
        $this->assertSame('GROUP', $stored['artists'][1]['kind']);
        $this->assertSame('PERSON', $stored['artists'][2]['kind']);
        $this->assertSame(['instagram' => 'https://instagram.com/meli'], $stored['artists'][2]['links']);

        $this->assertStringNotContainsString('onclick', $stored['practical_info'][0]['content']);
        $this->assertSame('Est-ce gratuit ?', $stored['faq'][0]['question']);
    }

    public function test_present_resolves_artist_photos_and_available_pages(): void
    {
        $image = (new ImageDomainObject)->setId(5)->setPath('events/14/meli.webp');

        $pages = $this->service->present([
            'artists' => [
                ['name' => 'Méli', 'slug' => 'meli', 'kind' => 'PERSON', 'image_id' => 5, 'links' => []],
                ['name' => 'RH', 'slug' => 'rh', 'kind' => 'PERSON', 'image_id' => 77, 'links' => []],
            ],
            'practical_info' => [],
            'faq' => [['question' => 'Combien de places ?', 'answer' => '<p>2 maximum</p>']],
        ], new Collection([$image]));

        $this->assertSame(['artistes', 'faq'], $pages->available_pages);
        $this->assertStringEndsWith('events/14/meli.webp', $pages->artists[0]->photo_url);
        $this->assertNull($pages->artists[1]->photo_url);
        $this->assertCount(0, $pages->practical_info);
    }

    public function test_event_settings_expose_only_pages_with_content(): void
    {
        $settings = new EventSettingDomainObject;
        $this->assertSame([], $settings->getAvailableEventPages());

        $settings->setEventPages(json_encode([
            'artists' => [],
            'practical_info' => [['title' => 'Horaires', 'content' => null]],
            'faq' => [['question' => 'Q', 'answer' => 'R']],
        ]));

        $this->assertSame(['infos-pratiques', 'faq'], $settings->getAvailableEventPages());
    }
}
