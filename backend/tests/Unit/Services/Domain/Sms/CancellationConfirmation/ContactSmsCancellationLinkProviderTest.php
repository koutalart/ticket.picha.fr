<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Domain\Sms\CancellationConfirmation;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\ContactDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\ContactRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsCustomerDirectoryRepositoryInterface;
use HiEvents\Services\Domain\Contact\ContactPersonalLinkService;
use HiEvents\Services\Domain\Contact\SmsContactResolver;
use HiEvents\Services\Domain\PublicUrl\PublicBaseUrlResolver;
use HiEvents\Services\Domain\Sms\CancellationConfirmation\AttendanceSmsCancellationLinkProvider;
use HiEvents\Services\Domain\Sms\CancellationConfirmation\ContactSmsCancellationLinkProvider;
use HiEvents\Services\Domain\Sms\DTO\SmsReminderAttendeeDTO;
use HiEvents\Services\Domain\Sms\Reminder\SmsReminderPersonalLinkProviderInterface;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use Tests\Support\FakesPublicBaseUrl;
use Tests\TestCase;

class ContactSmsCancellationLinkProviderTest extends TestCase
{
    use FakesPublicBaseUrl;
    use MockeryPHPUnitIntegration;

    private const ORGANIZER_ID = 8;

    private const EVENT_ID = 13;

    private const ATTENDEE_ID = 77;

    private SmsCustomerDirectoryRepositoryInterface|MockInterface $directory;

    private AttendeeRepositoryInterface|MockInterface $attendees;

    private ContactRepositoryInterface|MockInterface $contacts;

    private SmsReminderPersonalLinkProviderInterface|MockInterface $attendanceLinks;

    private PublicBaseUrlResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.frontend_url' => 'https://ticket.picha.fr']);
        $this->resolver = $this->fakePublicBaseUrl([self::ORGANIZER_ID => 'mayottela1ere.fr']);

        $this->directory = Mockery::mock(SmsCustomerDirectoryRepositoryInterface::class);
        $this->attendees = Mockery::mock(AttendeeRepositoryInterface::class);
        $this->contacts = Mockery::mock(ContactRepositoryInterface::class);
        $this->attendanceLinks = Mockery::mock(SmsReminderPersonalLinkProviderInterface::class);

        $this->directory->shouldReceive('findAttendeesWithPhone')
            ->with(self::EVENT_ID, [self::ATTENDEE_ID])
            ->andReturn(collect([new SmsReminderAttendeeDTO(self::ATTENDEE_ID, '0639 01 78 18', 'Anicka', 'M')]))
            ->byDefault();
        $this->attendees->shouldReceive('findFirst')
            ->with(self::ATTENDEE_ID)
            ->andReturn((new AttendeeDomainObject)->setId(self::ATTENDEE_ID)->setEmail(' Anicka@Example.com '))
            ->byDefault();
    }

    public function test_contact_found_by_phone_gets_the_registration_page_with_their_code_on_the_custom_domain(): void
    {
        $this->contacts->shouldReceive('findFirstWhere')
            ->with(Mockery::on(fn (array $where) => $where['organizer_id'] === self::ORGANIZER_ID && ($where['phone'] ?? null) === '+262639017818'))
            ->andReturn($this->contact('k7mzq2xwpa'));

        self::assertSame(
            'https://mayottela1ere.fr/event/13/salon-mayotte-recrute?c=k7mzq2xwpa',
            $this->provider()->findForAttendee(self::ATTENDEE_ID, $this->event()),
        );
    }

    public function test_contact_is_found_by_email_when_the_phone_matches_no_contact(): void
    {
        $this->contacts->shouldReceive('findFirstWhere')
            ->with(Mockery::on(fn (array $where) => array_key_exists('phone', $where)))
            ->andReturnNull();
        $this->contacts->shouldReceive('findFirstWhere')
            ->with(Mockery::on(fn (array $where) => $where['organizer_id'] === self::ORGANIZER_ID && ($where['email'] ?? null) === 'anicka@example.com'))
            ->andReturn($this->contact('p3hx8wqrtz'));

        self::assertSame(
            'https://mayottela1ere.fr/event/13/salon-mayotte-recrute?c=p3hx8wqrtz',
            $this->provider()->findForAttendee(self::ATTENDEE_ID, $this->event()),
        );
    }

    public function test_platform_address_is_used_when_the_organizer_has_no_custom_domain(): void
    {
        $this->resolver = $this->fakePublicBaseUrl();
        $this->contacts->shouldReceive('findFirstWhere')->andReturn($this->contact('k7mzq2xwpa'));

        self::assertSame(
            'https://ticket.picha.fr/event/13/salon-mayotte-recrute?c=k7mzq2xwpa',
            $this->provider()->findForAttendee(self::ATTENDEE_ID, $this->event()),
        );
    }

    public function test_without_contact_the_attendance_page_stays_the_link(): void
    {
        $this->directory->shouldReceive('findAttendeesWithPhone')->andReturn(collect());
        $this->contacts->shouldReceive('findFirstWhere')->andReturnNull();
        $this->attendanceLinks->shouldReceive('findForAttendee')
            ->with(self::ATTENDEE_ID)
            ->andReturn('https://mayottela1ere.fr/presence/abc123');

        self::assertSame(
            'https://mayottela1ere.fr/presence/abc123',
            $this->provider()->findForAttendee(self::ATTENDEE_ID, $this->event()),
        );
    }

    private function contact(string $code): ContactDomainObject
    {
        return (new ContactDomainObject)->setId(31)->setOrganizerId(self::ORGANIZER_ID)->setPublicCode($code);
    }

    private function event(): EventDomainObject
    {
        return (new EventDomainObject)
            ->setId(self::EVENT_ID)
            ->setOrganizerId(self::ORGANIZER_ID)
            ->setTitle('Salon Mayotte Recrute');
    }

    private function provider(): ContactSmsCancellationLinkProvider
    {
        return new ContactSmsCancellationLinkProvider(
            $this->directory,
            $this->attendees,
            $this->contacts,
            new SmsContactResolver($this->contacts),
            new ContactPersonalLinkService($this->contacts, $this->resolver),
            new AttendanceSmsCancellationLinkProvider($this->attendanceLinks),
        );
    }
}
