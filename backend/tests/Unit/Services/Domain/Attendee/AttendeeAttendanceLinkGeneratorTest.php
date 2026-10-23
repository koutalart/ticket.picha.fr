<?php

namespace Tests\Unit\Services\Domain\Attendee;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\Services\Domain\Attendee\AttendeeAttendanceLinkGenerator;
use Tests\Support\FakesPublicBaseUrl;
use Tests\TestCase;

class AttendeeAttendanceLinkGeneratorTest extends TestCase
{
    use FakesPublicBaseUrl;

    private AttendeeAttendanceLinkGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.key' => 'base64:dGVzdC1rZXktZm9yLWF0dGVuZGFuY2UtbGlua3M=',
            'app.frontend_url' => 'https://ticket.picha.fr',
        ]);

        $this->fakePublicBaseUrl([8 => 'mayottela1ere.fr'], [14 => 8]);
        $this->generator = new AttendeeAttendanceLinkGenerator();
    }

    public function testCodeIsStableAndPersonal(): void
    {
        $first = $this->createAttendee(5);
        $second = $this->createAttendee(6);

        $this->assertSame($this->generator->generateCode($first), $this->generator->generateCode($first));
        $this->assertNotSame($this->generator->generateCode($first), $this->generator->generateCode($second));
        $this->assertSame(40, strlen($this->generator->generateCode($first)));
    }

    public function testCodeDependsOnTheApplicationKey(): void
    {
        $attendee = $this->createAttendee(5);
        $code = $this->generator->generateCode($attendee);

        config(['app.key' => 'base64:YW5vdGhlci1rZXk=']);

        $this->assertNotSame($code, $this->generator->generateCode($attendee));
    }

    public function testValidatesCodes(): void
    {
        $attendee = $this->createAttendee(5);

        $this->assertTrue($this->generator->isValidCode($attendee, $this->generator->generateCode($attendee)));
        $this->assertFalse($this->generator->isValidCode($attendee, $this->generator->generateCode($this->createAttendee(6))));
        $this->assertFalse($this->generator->isValidCode($attendee, ''));
        $this->assertFalse($this->generator->isValidCode($attendee, null));
    }

    public function testBuildsThePublicUrl(): void
    {
        $attendee = $this->createAttendee(5);

        $this->assertSame(
            'https://ticket.picha.fr/venue/2/a_short5?code=' . $this->generator->generateCode($attendee),
            $this->generator->getAttendanceUrl($attendee),
        );
    }

    public function testBuildsThePublicUrlOnTheOrganizerCustomDomain(): void
    {
        $attendee = $this->createAttendee(5)->setEventId(14);

        $this->assertSame(
            'https://mayottela1ere.fr/venue/14/a_short5?code=' . $this->generator->generateCode($attendee),
            $this->generator->getAttendanceUrl($attendee),
        );
    }

    private function createAttendee(int $id): AttendeeDomainObject
    {
        return (new AttendeeDomainObject())
            ->setId($id)
            ->setEventId(2)
            ->setShortId('a_short' . $id);
    }
}
