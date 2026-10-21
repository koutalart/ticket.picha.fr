<?php

namespace Tests\Unit\Services\Domain\Sms\Reminder;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Services\Domain\Attendee\AttendeeAttendanceLinkGenerator;
use HiEvents\Services\Domain\Sms\Reminder\AttendanceSmsReminderPersonalLinkProvider;
use Mockery as m;
use Tests\TestCase;

class AttendanceSmsReminderPersonalLinkProviderTest extends TestCase
{
    private AttendeeRepositoryInterface $attendeeRepository;
    private EventSettingsRepositoryInterface $eventSettingsRepository;
    private AttendeeAttendanceLinkGenerator $linkGenerator;
    private AttendanceSmsReminderPersonalLinkProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->attendeeRepository = m::mock(AttendeeRepositoryInterface::class);
        $this->eventSettingsRepository = m::mock(EventSettingsRepositoryInterface::class);
        $this->linkGenerator = m::mock(AttendeeAttendanceLinkGenerator::class);
        $this->provider = new AttendanceSmsReminderPersonalLinkProvider(
            $this->attendeeRepository,
            $this->eventSettingsRepository,
            $this->linkGenerator,
        );
    }

    public function test_returns_the_personal_link_when_attendance_responses_are_enabled(): void
    {
        $attendee = (new AttendeeDomainObject())->setId(5)->setEventId(14);
        $this->attendeeRepository->shouldReceive('findFirst')->with(5)->andReturn($attendee);
        $this->eventSettingsRepository->shouldReceive('findFirstWhere')->with(['event_id' => 14])
            ->andReturn((new EventSettingDomainObject())->setAllowAttendeeAttendanceResponse(true));
        $this->linkGenerator->shouldReceive('getAttendanceUrl')->with($attendee)->andReturn('https://ticket.picha.fr/venue/14/abc?code=x');

        $this->assertSame('https://ticket.picha.fr/venue/14/abc?code=x', $this->provider->findForAttendee(5));
    }

    public function test_returns_null_when_attendance_responses_are_disabled(): void
    {
        $this->attendeeRepository->shouldReceive('findFirst')->with(5)->andReturn((new AttendeeDomainObject())->setId(5)->setEventId(14));
        $this->eventSettingsRepository->shouldReceive('findFirstWhere')
            ->andReturn((new EventSettingDomainObject())->setAllowAttendeeAttendanceResponse(false));
        $this->linkGenerator->shouldNotReceive('getAttendanceUrl');

        $this->assertNull($this->provider->findForAttendee(5));
    }

    public function test_returns_null_for_an_unknown_attendee(): void
    {
        $this->attendeeRepository->shouldReceive('findFirst')->with(99)->andReturn(null);

        $this->assertNull($this->provider->findForAttendee(99));
    }
}
