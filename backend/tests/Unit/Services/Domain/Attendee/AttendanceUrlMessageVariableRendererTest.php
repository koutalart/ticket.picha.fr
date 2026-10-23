<?php

namespace Tests\Unit\Services\Domain\Attendee;

use HiEvents\Services\Domain\Attendee\AttendanceUrlMessageVariableRenderer;
use Tests\TestCase;

class AttendanceUrlMessageVariableRendererTest extends TestCase
{
    private const URL = 'https://ticket.picha.fr/venue/2/a_short?code=abc123';

    private AttendanceUrlMessageVariableRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.customer_locale' => 'fr']);
        $this->renderer = new AttendanceUrlMessageVariableRenderer();
    }

    public function testDetectsTheVariable(): void
    {
        $this->assertTrue($this->renderer->containsVariable('<p>{{ attendee.attendance_url }}</p>'));
        $this->assertTrue($this->renderer->containsVariable('<p>{{attendee.attendance_url}}</p>'));
        $this->assertTrue($this->renderer->containsVariable('<a href="%7B%7B%20attendee.attendance_url%20%7D%7D">x</a>'));
        $this->assertFalse($this->renderer->containsVariable('<p>{{ ticket.url }}</p>'));
    }

    public function testRendersTheVariableAsALinkInText(): void
    {
        $html = $this->renderer->render('<p>Merci de répondre : {{&nbsp;attendee.attendance_url }}</p>', self::URL);

        $this->assertSame(
            '<p>Merci de répondre : <a href="https://ticket.picha.fr/venue/2/a_short?code=abc123">Confirmer ou annuler ma venue</a></p>',
            $html,
        );
    }

    public function testRendersTheVariableInsideAHref(): void
    {
        $html = $this->renderer->render('<a href="{{ attendee.attendance_url }}">Répondre</a>', self::URL);

        $this->assertSame('<a href="https://ticket.picha.fr/venue/2/a_short?code=abc123">Répondre</a>', $html);
    }

    public function testRendersTheVariableInsideAnEncodedHref(): void
    {
        $html = $this->renderer->render('<a href="%7B%7B%20attendee.attendance_url%20%7D%7D">Répondre</a>', self::URL);

        $this->assertSame('<a href="https://ticket.picha.fr/venue/2/a_short?code=abc123">Répondre</a>', $html);
    }

    public function testRendersAPlaceholderWithoutUrl(): void
    {
        $html = $this->renderer->render('<p>{{ attendee.attendance_url }}</p>', null);

        $this->assertSame('<p>[Confirmer ou annuler ma venue]</p>', $html);
    }

    public function testStripRemovesTheVariable(): void
    {
        $this->assertSame('<p>Bonjour </p>', $this->renderer->strip('<p>Bonjour {{ attendee.attendance_url }}</p>'));
    }

    public function testLeavesOtherMessagesUntouched(): void
    {
        $this->assertSame('<p>Bonjour</p>', $this->renderer->render('<p>Bonjour</p>', self::URL));
    }
}
