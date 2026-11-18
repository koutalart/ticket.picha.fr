<?php

namespace Tests\Unit\Services\Domain\Event\Presentation;

use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\ImageDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\QuestionDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Repository\Interfaces\ImageRepositoryInterface;
use HiEvents\Repository\Interfaces\QuestionRepositoryInterface;
use HiEvents\Services\Domain\Event\Presentation\EventPresentationTemplateService;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class EventPresentationTemplateServiceTest extends TestCase
{
    private MockInterface $settings;

    private MockInterface $events;

    private MockInterface $images;

    private MockInterface $questions;

    private EventPresentationTemplateService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settings = Mockery::mock(EventSettingsRepositoryInterface::class);
        $this->events = Mockery::mock(EventRepositoryInterface::class);
        $this->images = Mockery::mock(ImageRepositoryInterface::class);
        $this->questions = Mockery::mock(QuestionRepositoryInterface::class);
        $this->service = new EventPresentationTemplateService($this->settings, $this->events, $this->images, $this->questions);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_it_applies_the_halloween_layout_with_the_poster_colour_and_the_organizer_logo(): void
    {
        $this->settings->shouldReceive('findFirstWhere')->once()->with(['event_id' => 23])
            ->andReturn((new EventSettingDomainObject)->setId(400));
        $this->images->shouldReceive('findFirstWhere')->once()->with([
            'entity_id' => 20,
            'entity_type' => OrganizerDomainObject::class,
            'type' => 'ORGANIZER_LOGO',
        ])->andReturn((new ImageDomainObject)->setId(230));

        $saved = null;
        $this->settings->shouldReceive('updateFromArray')->once()->with(400, Mockery::on(function (array $attributes) use (&$saved) {
            $saved = $attributes;

            return true;
        }));
        $this->events->shouldReceive('updateWhere')->once()->with(['category' => 'MUSIC'], ['id' => 23]);
        $this->questions->shouldReceive('findFirstWhere')->once()
            ->with(['event_id' => 23, 'type' => 'PHONE', 'belongs_to' => 'ORDER'])->andReturnNull();
        $this->questions->shouldReceive('create')->once()->with(Mockery::on(fn (array $question) => $question['event_id'] === 23
            && $question['title'] === 'Numéro de téléphone'
            && $question['required'] === true
            && $question['type'] === 'PHONE'
            && $question['belongs_to'] === 'ORDER'));

        $this->service->apply(23, 20, '#1f8fffff');

        $this->assertSame('Acheter', $saved['continue_button_text']);
        $this->assertTrue($saved['hide_getting_started_page']);
        $this->assertSame('MIRROR_COVER_IMAGE', $saved['homepage_background_type']);
        $this->assertSame('#1f8fffff', $saved['homepage_primary_color']);
        $this->assertSame('CARD', $saved['homepage_theme_settings']['hero_layout']);
        $this->assertSame('#1f8fffff', $saved['homepage_theme_settings']['accent']);
        $this->assertSame('Outfit', $saved['homepage_theme_settings']['font_family']);
        $this->assertTrue($saved['ticket_design_settings']['enabled']);
        $this->assertSame(230, $saved['ticket_design_settings']['logo_image_id']);
        $this->assertSame('#1f8fffff', $saved['ticket_design_settings']['accent_color']);
        $this->assertTrue($saved['homepage_footer_settings']['newsletter_enabled']);
    }

    public function test_without_poster_colour_it_uses_the_default_accent_and_no_logo(): void
    {
        $this->settings->shouldReceive('findFirstWhere')->andReturn((new EventSettingDomainObject)->setId(401));
        $this->images->shouldReceive('findFirstWhere')->andReturnNull();
        $saved = null;
        $this->settings->shouldReceive('updateFromArray')->once()->with(401, Mockery::on(function (array $attributes) use (&$saved) {
            $saved = $attributes;

            return true;
        }));
        $this->events->shouldReceive('updateWhere')->once();
        $this->questions->shouldReceive('findFirstWhere')->andReturn((new QuestionDomainObject)->setId(54));
        $this->questions->shouldNotReceive('create');

        $this->service->apply(24, 20);

        $this->assertSame(EventPresentationTemplateService::DEFAULT_ACCENT, $saved['homepage_primary_color']);
        $this->assertNull($saved['ticket_design_settings']['logo_image_id']);
    }

    public function test_an_event_without_settings_is_refused(): void
    {
        $this->settings->shouldReceive('findFirstWhere')->andReturnNull();
        $this->settings->shouldNotReceive('updateFromArray');

        $this->expectException(ResourceNotFoundException::class);

        $this->service->apply(99, 20);
    }
}
