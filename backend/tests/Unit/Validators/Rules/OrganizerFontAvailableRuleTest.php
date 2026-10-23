<?php

namespace Tests\Unit\Validators\Rules;

use HiEvents\Services\Domain\Organizer\Font\OrganizerFontService;
use HiEvents\Validators\Rules\OrganizerFontAvailableRule;
use Mockery as m;
use Tests\TestCase;

class OrganizerFontAvailableRuleTest extends TestCase
{
    private OrganizerFontService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = m::mock(OrganizerFontService::class);
        $this->app->instance(OrganizerFontService::class, $this->service);
    }

    private function fails(OrganizerFontAvailableRule $rule, mixed $value): bool
    {
        $failed = false;
        $rule->validate('homepage_theme_settings.font_family', $value, function () use (&$failed) {
            $failed = true;
        });

        return $failed;
    }

    public function test_regular_fonts_are_not_checked(): void
    {
        $this->service->shouldNotReceive('isAvailableForOrganizer');

        $this->assertFalse($this->fails(OrganizerFontAvailableRule::forOrganizer(8), 'Montserrat'));
        $this->assertFalse($this->fails(OrganizerFontAvailableRule::forOrganizer(8), null));
    }

    public function test_organizer_font_requires_an_uploaded_regular_file(): void
    {
        $this->service->shouldReceive('isAvailableForOrganizer')->with(8)->andReturn(false, true);

        $this->assertTrue($this->fails(OrganizerFontAvailableRule::forOrganizer('8'), 'organizer_font'));
        $this->assertFalse($this->fails(OrganizerFontAvailableRule::forOrganizer('8'), 'organizer_font'));
    }

    public function test_event_settings_check_the_font_of_the_event_organizer(): void
    {
        $this->service->shouldReceive('isAvailableForEvent')->with(14)->andReturn(false);

        $this->assertTrue($this->fails(OrganizerFontAvailableRule::forEvent(14), 'organizer_font'));
    }

    public function test_missing_route_parameter_rejects_the_organizer_font(): void
    {
        $this->assertTrue($this->fails(OrganizerFontAvailableRule::forEvent(null), 'organizer_font'));
    }
}
