<?php

namespace Tests\Unit\Services\Domain\Sms\CheckInWelcome;

use HiEvents\DomainObjects\Enums\SmsMessageAuthorType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OrganizerSmsSettingDomainObject;
use HiEvents\DomainObjects\SmsCheckinWelcomeDomainObject;
use HiEvents\DomainObjects\SmsMessageDomainObject;
use HiEvents\Exceptions\SmsGatewayException;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerSmsSettingRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsCheckinWelcomeRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsCustomerDirectoryRepositoryInterface;
use HiEvents\Services\Domain\Sms\CheckInWelcome\SmsCheckInWelcomeMessageGenerator;
use HiEvents\Services\Domain\Sms\CheckInWelcome\SmsCheckInWelcomeService;
use HiEvents\Services\Domain\Sms\DTO\SendSmsDTO;
use HiEvents\Services\Domain\Sms\DTO\SmsCheckInWelcomeMessageDTO;
use HiEvents\Services\Domain\Sms\DTO\SmsReminderAttendeeDTO;
use HiEvents\Services\Domain\Sms\SmsSendService;
use Illuminate\Database\UniqueConstraintViolationException;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use Psr\Log\NullLogger;
use Tests\TestCase;

class SmsCheckInWelcomeServiceTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const ORGANIZER_ID = 4;

    private const EVENT_ID = 7;

    private const ATTENDEE_ID = 120;

    private OrganizerSmsSettingRepositoryInterface|MockInterface $settingsRepository;

    private SmsCustomerDirectoryRepositoryInterface|MockInterface $directoryRepository;

    private SmsCheckinWelcomeRepositoryInterface|MockInterface $welcomeRepository;

    private SmsCheckInWelcomeMessageGenerator|MockInterface $generator;

    private SmsSendService|MockInterface $sendService;

    private SmsCheckInWelcomeService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $eventRepository = Mockery::mock(EventRepositoryInterface::class);
        $eventRepository->shouldReceive('findFirstWhere')->with(['id' => self::EVENT_ID])->andReturn(
            (new EventDomainObject)->setId(self::EVENT_ID)->setOrganizerId(self::ORGANIZER_ID)->setTitle('Halloween')
        );
        $this->settingsRepository = Mockery::mock(OrganizerSmsSettingRepositoryInterface::class);
        $this->settingsRepository->shouldReceive('findFirstWhere')->andReturn(
            (new OrganizerSmsSettingDomainObject)->setOrganizerId(self::ORGANIZER_ID)->setCheckinWelcomeSmsEnabled(true)
        )->byDefault();
        $this->directoryRepository = Mockery::mock(SmsCustomerDirectoryRepositoryInterface::class);
        $this->directoryRepository->shouldReceive('findAttendeesWithPhone')->andReturn(collect([
            new SmsReminderAttendeeDTO(attendeeId: self::ATTENDEE_ID, phone: '0639 25 02 06', firstName: 'Nadia', lastName: 'M'),
        ]))->byDefault();
        $this->welcomeRepository = Mockery::mock(SmsCheckinWelcomeRepositoryInterface::class);
        $this->welcomeRepository->shouldReceive('findFirstWhere')->andReturnNull()->byDefault();
        $this->welcomeRepository->shouldReceive('create')->andReturn((new SmsCheckinWelcomeDomainObject)->setId(1))->byDefault();
        $this->welcomeRepository->shouldReceive('updateFromArray')->andReturnUsing(
            fn (int $id, array $attributes) => (new SmsCheckinWelcomeDomainObject)->setId($id)->setStatus($attributes['status'])
        )->byDefault();
        $this->generator = Mockery::mock(SmsCheckInWelcomeMessageGenerator::class);
        $this->generator->shouldReceive('generate')->andReturn(
            new SmsCheckInWelcomeMessageDTO(content: 'Nadia, bienvenue à Halloween !', source: SmsCheckInWelcomeMessageGenerator::SOURCE_MODEL)
        )->byDefault();
        $this->sendService = Mockery::mock(SmsSendService::class);

        $this->service = new SmsCheckInWelcomeService(
            $eventRepository,
            $this->settingsRepository,
            $this->directoryRepository,
            $this->welcomeRepository,
            $this->generator,
            $this->sendService,
            new NullLogger,
        );
    }

    public function test_sends_one_welcome_on_first_check_in(): void
    {
        $this->welcomeRepository->shouldReceive('create')->once()->with(Mockery::on(
            fn (array $attributes) => $attributes['event_id'] === self::EVENT_ID
                && $attributes['phone'] === '+262639250206'
                && $attributes['status'] === 'SENDING'
        ))->andReturn((new SmsCheckinWelcomeDomainObject)->setId(1));
        $this->sendService->shouldReceive('send')->once()->with(Mockery::on(
            fn (SendSmsDTO $sms) => $sms->organizerId === self::ORGANIZER_ID
                && $sms->phone === '+262639250206'
                && $sms->content === 'Nadia, bienvenue à Halloween !'
                && $sms->authorType === SmsMessageAuthorType::AUTOMATIC
        ))->andReturn((new SmsMessageDomainObject)->setId(55));

        $result = $this->service->sendForAttendee(self::EVENT_ID, self::ATTENDEE_ID);

        $this->assertSame('SENT', $result?->getStatus());
    }

    public function test_does_nothing_when_setting_is_disabled(): void
    {
        $this->settingsRepository->shouldReceive('findFirstWhere')->andReturn(
            (new OrganizerSmsSettingDomainObject)->setOrganizerId(self::ORGANIZER_ID)->setCheckinWelcomeSmsEnabled(false)
        );
        $this->directoryRepository->shouldNotReceive('findAttendeesWithPhone');
        $this->welcomeRepository->shouldNotReceive('create');
        $this->sendService->shouldNotReceive('send');

        $this->assertNull($this->service->sendForAttendee(self::EVENT_ID, self::ATTENDEE_ID));
    }

    public function test_skips_attendee_without_phone_silently(): void
    {
        $this->directoryRepository->shouldReceive('findAttendeesWithPhone')->andReturn(collect([
            new SmsReminderAttendeeDTO(attendeeId: self::ATTENDEE_ID, phone: null, firstName: 'Sur place', lastName: 'N°012'),
        ]));
        $this->welcomeRepository->shouldNotReceive('create');
        $this->generator->shouldNotReceive('generate');
        $this->sendService->shouldNotReceive('send');

        $this->assertNull($this->service->sendForAttendee(self::EVENT_ID, self::ATTENDEE_ID));
    }

    public function test_never_sends_twice_to_the_same_phone_for_the_event(): void
    {
        $this->welcomeRepository->shouldReceive('findFirstWhere')->with([
            'event_id' => self::EVENT_ID,
            'phone' => '+262639250206',
        ])->andReturn((new SmsCheckinWelcomeDomainObject)->setId(1)->setStatus('SENT'));
        $this->welcomeRepository->shouldNotReceive('create');
        $this->sendService->shouldNotReceive('send');

        $this->assertNull($this->service->sendForAttendee(self::EVENT_ID, self::ATTENDEE_ID + 1));
    }

    public function test_concurrent_scan_of_the_same_phone_loses_the_claim(): void
    {
        $this->welcomeRepository->shouldReceive('create')->andThrow(Mockery::mock(UniqueConstraintViolationException::class));
        $this->sendService->shouldNotReceive('send');

        $this->assertNull($this->service->sendForAttendee(self::EVENT_ID, self::ATTENDEE_ID));
    }

    public function test_failed_send_is_recorded_and_not_thrown(): void
    {
        $this->sendService->shouldReceive('send')->andThrow(new SmsGatewayException('down'));

        $result = $this->service->sendForAttendee(self::EVENT_ID, self::ATTENDEE_ID);

        $this->assertSame('FAILED', $result?->getStatus());
    }
}
