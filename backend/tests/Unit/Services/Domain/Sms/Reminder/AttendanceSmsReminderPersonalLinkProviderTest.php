<?php

namespace Tests\Unit\Services\Domain\Sms\Reminder;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Services\Domain\Attendee\AttendeeAttendanceLinkGenerator;
use HiEvents\Services\Domain\Sms\Reminder\AttendanceSmsReminderPersonalLinkProvider;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Services\Domain\Sms\RegistrationConfirmation\SmsRegistrationConfirmationService;
use HiEvents\Services\Domain\Sms\Reminder\SmsReminderMessageRenderer;
use Mockery as m;
use Tests\Support\FakesPublicBaseUrl;
use Tests\TestCase;

class AttendanceSmsReminderPersonalLinkProviderTest extends TestCase
{
    use FakesPublicBaseUrl;

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

    public function test_sms_link_stays_on_the_organizer_custom_domain(): void
    {
        config(['app.frontend_url' => 'https://ticket.picha.fr']);
        $this->fakePublicBaseUrl([8 => 'mayottela1ere.fr'], [14 => 8]);

        $attendee = (new AttendeeDomainObject())->setId(5)->setEventId(14)->setShortId('a_7Kp2Qx');
        $this->attendeeRepository->shouldReceive('findFirst')->with(5)->andReturn($attendee);
        $this->eventSettingsRepository->shouldReceive('findFirstWhere')->with(['event_id' => 14])
            ->andReturn((new EventSettingDomainObject())->setAllowAttendeeAttendanceResponse(true));

        $provider = new AttendanceSmsReminderPersonalLinkProvider(
            $this->attendeeRepository,
            $this->eventSettingsRepository,
            new AttendeeAttendanceLinkGenerator(),
        );
        $link = $provider->findForAttendee(5);

        $sms = (new SmsReminderMessageRenderer())->render(
            SmsRegistrationConfirmationService::defaultMessage(),
            (new EventDomainObject())->setId(14)->setOrganizerId(8)->setTitle('Le concert des générations')
                ->setStartDate('2026-11-07 15:00:00')->setTimezone('Indian/Mayotte'),
            'Anicka',
            $link,
        );

        $this->assertStringStartsWith('https://mayottela1ere.fr/venue/14/a_7Kp2Qx?code=', $link);
        $this->assertStringContainsString($link, $sms);
        $this->assertStringNotContainsString('ticket.picha.fr', $sms);
    }

    public function test_returns_null_for_an_unknown_attendee(): void
    {
        $this->attendeeRepository->shouldReceive('findFirst')->with(99)->andReturn(null);

        $this->assertNull($this->provider->findForAttendee(99));
    }
}
