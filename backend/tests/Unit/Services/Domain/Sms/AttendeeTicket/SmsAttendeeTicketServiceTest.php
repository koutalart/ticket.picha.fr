<?php

namespace Tests\Unit\Services\Domain\Sms\AttendeeTicket;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\Enums\CustomerAddressForm;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\SmsMessageDomainObject;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Services\Domain\Customer\CustomerLocaleResolver;
use HiEvents\Services\Domain\PublicUrl\PublicBaseUrlResolver;
use HiEvents\Services\Domain\Sms\AttendeeTicket\SmsAttendeeTicketService;
use HiEvents\Services\Domain\Sms\DTO\SendSmsDTO;
use HiEvents\Services\Domain\Sms\Reminder\SmsReminderMessageRenderer;
use HiEvents\Services\Domain\Sms\SmsShortLinkService;
use HiEvents\Services\Domain\Sms\SmsWithLinkSender;
use Illuminate\Support\Carbon;
use Mockery as m;
use Tests\TestCase;

class SmsAttendeeTicketServiceTest extends TestCase
{
    public function testSendingAllowedDuringTheDayInEventTimezone(): void
    {
        $this->assertNull(SmsAttendeeTicketService::nextAllowedTime(Carbon::parse('2026-11-11 10:00:00', 'UTC'), 'Indian/Mayotte'));
    }

    public function testEveningIsPostponedToNextMorning(): void
    {
        $next = SmsAttendeeTicketService::nextAllowedTime(Carbon::parse('2026-11-11 17:30:00', 'UTC'), 'Indian/Mayotte');

        $this->assertSame('2026-11-12 05:00:00', $next->toDateTimeString());
    }

    public function testEarlyMorningIsPostponedToEightTheSameDay(): void
    {
        $next = SmsAttendeeTicketService::nextAllowedTime(Carbon::parse('2026-11-11 02:00:00', 'UTC'), 'Indian/Mayotte');

        $this->assertSame('2026-11-11 05:00:00', $next->toDateTimeString());
    }

    public function testNothingSentWithoutOwnPhone(): void
    {
        [$service, $sender] = $this->service((new AttendeeDomainObject())
            ->setId(5)->setEventId(14)->setStatus(AttendeeStatus::ACTIVE->name)->setShortId('a_1')->setFirstName('Ben'));
        $sender->shouldNotReceive('send');

        $this->assertFalse($service->send(5, 14));
    }

    public function testSendsTextThenShortLinkToTheAttendeePhone(): void
    {
        [$service, $sender] = $this->service((new AttendeeDomainObject())
            ->setId(5)->setEventId(14)->setStatus(AttendeeStatus::ACTIVE->name)->setShortId('a_1')
            ->setFirstName('El Djine')->setPhone('0639 12 34 56'));

        $sender->shouldReceive('send')->once()->withArgs(function (SendSmsDTO $sms, ?string $link) {
            return $sms->organizerId === 8
                && str_contains($sms->phone, '639123456')
                && str_contains($sms->content, 'El Djine')
                && ! str_contains($sms->content, 'https://')
                && $link === 'https://ticket.picha.fr/q/abc';
        })->andReturn(new SmsMessageDomainObject());

        $this->assertTrue($service->send(5, 14));
    }

    private function service(AttendeeDomainObject $attendee): array
    {
        $attendees = m::mock(AttendeeRepositoryInterface::class);
        $attendees->shouldReceive('findFirstWhere')->andReturn($attendee);

        $event = (new EventDomainObject())->setId(14)->setOrganizerId(8)->setTitle('Concert')
            ->setTimezone('Indian/Mayotte')->setStartDate('2026-11-14 14:30:00');
        $events = m::mock(EventRepositoryInterface::class);
        $events->shouldReceive('findFirst')->andReturn($event);

        $urls = m::mock(PublicBaseUrlResolver::class);
        $urls->shouldReceive('forEvent')->andReturn('https://mayottela1ere.fr');
        $this->app->instance(PublicBaseUrlResolver::class, $urls);

        $shortLinks = m::mock(SmsShortLinkService::class);
        $shortLinks->shouldReceive('shorten')
            ->with(m::type(EventDomainObject::class), 'https://mayottela1ere.fr/product/14/a_1')
            ->andReturn('https://ticket.picha.fr/q/abc');

        $locale = m::mock(CustomerLocaleResolver::class);
        $locale->shouldReceive('addressFormForOrganizerId')->andReturn(CustomerAddressForm::VOUS);

        $sender = m::mock(SmsWithLinkSender::class);

        return [new SmsAttendeeTicketService($attendees, $events, new SmsReminderMessageRenderer(), $shortLinks, $sender, $locale), $sender];
    }
}
