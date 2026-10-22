<?php

namespace Tests\Unit\Services\Domain\Sms\CancellationConfirmation;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\Enums\SmsCancellationSource;
use HiEvents\DomainObjects\Enums\SmsMessageAuthorType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\SmsCancellationConfirmationDomainObject;
use HiEvents\DomainObjects\SmsMessageDomainObject;
use HiEvents\DomainObjects\SmsOptOutDomainObject;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Exceptions\SmsGatewayException;
use HiEvents\Exceptions\SmsGatewayNotConfiguredException;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsCancellationConfirmationRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsCustomerDirectoryRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsOptOutRepositoryInterface;
use HiEvents\Services\Domain\Sms\CancellationConfirmation\AttendanceSmsCancellationLinkProvider;
use HiEvents\Services\Domain\Sms\CancellationConfirmation\SmsCancellationConfirmationService;
use HiEvents\Services\Domain\Sms\CancellationConfirmation\SmsCancellationLinkProviderInterface;
use HiEvents\Services\Domain\Sms\DTO\SendSmsDTO;
use HiEvents\Services\Domain\Sms\DTO\SmsReminderAttendeeDTO;
use HiEvents\Services\Domain\Sms\Reminder\SmsReminderMessageRenderer;
use HiEvents\Services\Domain\Sms\Reminder\SmsReminderPersonalLinkProviderInterface;
use HiEvents\Services\Domain\Sms\SmsSendService;
use Illuminate\Database\UniqueConstraintViolationException;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use Psr\Log\NullLogger;
use Tests\TestCase;
use Tests\Unit\Support\StaticCustomerLocaleResolver;
use HiEvents\DomainObjects\Enums\CustomerAddressForm;

class SmsCancellationConfirmationServiceTest extends TestCase
{
    private StaticCustomerLocaleResolver $customerLocaleResolver;

    use MockeryPHPUnitIntegration;

    private const ORGANIZER_ID = 8;

    private const EVENT_ID = 13;

    private const ORDER_ID = 40;

    private const TEMPLATE = "C'est noté {prenom}, votre inscription à {evenement} est annulée. Pour revenir : {lien}\n\nKultur'm via PICHA Ticket";

    private AttendeeRepositoryInterface|MockInterface $attendeeRepository;

    private OrderRepositoryInterface|MockInterface $orderRepository;

    private EventSettingsRepositoryInterface|MockInterface $settingsRepository;

    private SmsCustomerDirectoryRepositoryInterface|MockInterface $directoryRepository;

    private SmsOptOutRepositoryInterface|MockInterface $optOutRepository;

    private SmsCancellationConfirmationRepositoryInterface|MockInterface $confirmationRepository;

    private SmsCancellationLinkProviderInterface|MockInterface $linkProvider;

    private SmsSendService|MockInterface $sendService;

    private SmsCancellationConfirmationService $service;

    /** @var SendSmsDTO[] */
    private array $sent = [];

    /** @var array<int, array> */
    private array $claims = [];

    /** @var array<int, array> */
    private array $updates = [];

    /** @var array<int, array> */
    private array $rows = [];

    /** @var string[] */
    private array $waveStarts = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->attendeeRepository = Mockery::mock(AttendeeRepositoryInterface::class);
        $this->orderRepository = Mockery::mock(OrderRepositoryInterface::class);
        $eventRepository = Mockery::mock(EventRepositoryInterface::class);
        $this->settingsRepository = Mockery::mock(EventSettingsRepositoryInterface::class);
        $this->directoryRepository = Mockery::mock(SmsCustomerDirectoryRepositoryInterface::class);
        $this->optOutRepository = Mockery::mock(SmsOptOutRepositoryInterface::class);
        $this->confirmationRepository = Mockery::mock(SmsCancellationConfirmationRepositoryInterface::class);
        $this->linkProvider = Mockery::mock(SmsCancellationLinkProviderInterface::class);
        $this->sendService = Mockery::mock(SmsSendService::class);

        $this->attendeeRepository->shouldReceive('findFirst')->andReturnUsing(
            fn (int $id) => $this->attendee($id, cancelledByAttendee: true)
        )->byDefault();
        $this->settingsRepository->shouldReceive('findFirstWhere')->with(['event_id' => self::EVENT_ID])
            ->andReturn($this->settings(true, self::TEMPLATE, false))->byDefault();
        $eventRepository->shouldReceive('findFirstWhere')->with(['id' => self::EVENT_ID])->andReturn(
            (new EventDomainObject)
                ->setId(self::EVENT_ID)
                ->setOrganizerId(self::ORGANIZER_ID)
                ->setTitle('Mayotte La 1ère en live')
                ->setStartDate('2026-11-07 15:00:00')
                ->setTimezone('Indian/Mayotte')
        );
        $this->optOutRepository->shouldReceive('findFirstWhere')->andReturnNull()->byDefault();
        $this->linkProvider->shouldReceive('findForAttendee')->andReturnUsing(
            fn (int $attendeeId) => 'https://ticket.picha.fr/event/13/live?c=code'.$attendeeId
        )->byDefault();

        $this->confirmationRepository->shouldReceive('findFirstWhere')->andReturnNull()->byDefault();
        $this->confirmationRepository->shouldReceive('createUnlessPhoneConfirmedSince')->andReturnUsing(
            function (array $attributes, string $since, array $skippedAttributes) {
                $this->waveStarts[] = $since;
                foreach ($this->rows as $claim) {
                    if ($claim['event_id'] === $attributes['event_id']
                        && $claim['phone'] === $attributes['phone']
                        && in_array($claim['status'], ['SENDING', 'SENT'], true)) {
                        $attributes = array_merge($attributes, $skippedAttributes);
                        break;
                    }
                }

                $id = count($this->claims) + 1;
                $this->claims[$id] = $attributes;
                $this->rows[$id] = $attributes;

                return (new SmsCancellationConfirmationDomainObject)
                    ->setId($id)
                    ->setAttendeeId($attributes['attendee_id'])
                    ->setStatus($attributes['status']);
            }
        )->byDefault();
        $this->confirmationRepository->shouldReceive('updateFromArray')->andReturnUsing(function (int $id, array $attributes) {
            $this->updates[$id] = $attributes;
            $this->rows[$id] = array_merge($this->rows[$id], $attributes);

            return new SmsCancellationConfirmationDomainObject;
        })->byDefault();

        $this->sendService->shouldReceive('send')->andReturnUsing(function (SendSmsDTO $sms) {
            $this->sent[] = $sms;

            return (new SmsMessageDomainObject)->setId(900 + count($this->sent));
        })->byDefault();

        $this->service = new SmsCancellationConfirmationService(
            $this->attendeeRepository,
            $this->orderRepository,
            $eventRepository,
            $this->settingsRepository,
            $this->directoryRepository,
            $this->optOutRepository,
            $this->confirmationRepository,
            new SmsReminderMessageRenderer,
            $this->linkProvider,
            $this->sendService,
            new NullLogger,
            $this->customerLocaleResolver = new StaticCustomerLocaleResolver,
        );
    }

    public function test_the_participant_cancelling_receives_the_message_with_their_personal_link(): void
    {
        $this->phones([1], [$this->withPhone(1, '0639 25 02 06', 'Awa')]);

        $result = $this->service->sendForAttendee(1);

        $this->assertSame(1, $result->sent);
        $this->assertSame(self::ORGANIZER_ID, $this->sent[0]->organizerId);
        $this->assertSame('+262639250206', $this->sent[0]->phone);
        $this->assertSame(SmsMessageAuthorType::AUTOMATIC, $this->sent[0]->authorType);
        $this->assertSame(
            "C'est noté Awa, votre inscription à Mayotte La 1ère en live est annulée. Pour revenir : https://ticket.picha.fr/event/13/live?c=code1\n\nKultur'm via PICHA Ticket",
            $this->sent[0]->content,
        );
        $this->assertSame([
            'organizer_id' => self::ORGANIZER_ID,
            'event_id' => self::EVENT_ID,
            'order_id' => self::ORDER_ID,
            'attendee_id' => 1,
            'phone' => '+262639250206',
            'cancelled_by' => 'PARTICIPANT',
            'status' => 'SENDING',
        ], $this->claims[1]);
        $this->assertSame(['status' => 'SENT', 'sms_message_id' => 901], $this->updates[1]);
    }

    public function test_the_link_and_its_label_are_removed_when_no_personal_link_can_be_built(): void
    {
        $this->linkProvider->shouldReceive('findForAttendee')->andReturnNull();
        $this->phones([1], [$this->withPhone(1, '+262639250206', 'Awa')]);

        $this->service->sendForAttendee(1);

        $this->assertSame(
            "C'est noté Awa, votre inscription à Mayotte La 1ère en live est annulée.\n\nKultur'm via PICHA Ticket",
            $this->sent[0]->content,
        );
        $this->assertStringNotContainsString('http', $this->sent[0]->content);
    }

    public function test_uses_the_generic_default_message_when_the_text_is_empty(): void
    {
        $this->settingsRepository->shouldReceive('findFirstWhere')->andReturn($this->settings(true, '  ', false));
        $this->linkProvider->shouldNotReceive('findForAttendee');
        $this->phones([1], [$this->withPhone(1, '+262639250206', 'Awa')]);

        $this->service->sendForAttendee(1);

        $this->assertSame(
            "C'est noté, votre inscription à Mayotte La 1ère en live est annulée et votre place est libérée. Au plaisir de vous revoir.",
            $this->sent[0]->content,
        );
    }

    public function test_the_default_message_says_tu_when_the_organizer_chose_it(): void
    {
        $this->customerLocaleResolver->addressForm = CustomerAddressForm::TU;
        $this->settingsRepository->shouldReceive('findFirstWhere')->andReturn($this->settings(true, '', false));
        $this->linkProvider->shouldNotReceive('findForAttendee');
        $this->phones([1], [$this->withPhone(1, '+262639250206', 'Awa')]);

        $this->service->sendForAttendee(1);

        $this->assertSame(
            "C'est noté, ton inscription à Mayotte La 1ère en live est annulée et ta place est libérée. Au plaisir de te revoir.",
            $this->sent[0]->content,
        );
    }

    public function test_nothing_is_sent_when_the_option_is_disabled(): void
    {
        $this->settingsRepository->shouldReceive('findFirstWhere')->andReturn($this->settings(false, self::TEMPLATE, true));
        $this->directoryRepository->shouldNotReceive('findAttendeesWithPhone');

        $result = $this->service->sendForAttendee(1);

        $this->assertSame(0, $result->sent);
        $this->assertSame([], $this->sent);
    }

    public function test_an_attendee_cancelled_by_the_organizer_receives_nothing_by_default(): void
    {
        $this->attendeeRepository->shouldReceive('findFirst')->with(1)->andReturn($this->attendee(1, cancelledByAttendee: false));
        $this->directoryRepository->shouldNotReceive('findAttendeesWithPhone');

        $this->service->sendForAttendee(1);

        $this->assertSame([], $this->sent);
    }

    public function test_an_attendee_cancelled_by_the_organizer_receives_it_when_the_organizer_enabled_it(): void
    {
        $this->settingsRepository->shouldReceive('findFirstWhere')->andReturn($this->settings(true, self::TEMPLATE, true));
        $this->attendeeRepository->shouldReceive('findFirst')->with(1)->andReturn($this->attendee(1, cancelledByAttendee: false));
        $this->phones([1], [$this->withPhone(1, '+262639250206', 'Awa')]);

        $result = $this->service->sendForAttendee(1);

        $this->assertSame(1, $result->sent);
        $this->assertSame('ORGANIZER', $this->claims[1]['cancelled_by']);
    }

    public function test_an_attendee_who_is_not_cancelled_receives_nothing(): void
    {
        $this->attendeeRepository->shouldReceive('findFirst')->with(1)->andReturn(
            $this->attendee(1, cancelledByAttendee: false)->setStatus(AttendeeStatus::ACTIVE->name)
        );
        $this->settingsRepository->shouldNotReceive('findFirstWhere');

        $this->service->sendForAttendee(1);

        $this->assertSame([], $this->sent);
    }

    public function test_an_order_cancelled_by_the_organizer_receives_nothing_by_default(): void
    {
        $this->cancelledOrder([$this->attendee(1, cancelledByAttendee: false)]);
        $this->directoryRepository->shouldNotReceive('findAttendeesWithPhone');

        $this->service->sendForOrder(self::ORDER_ID);

        $this->assertSame([], $this->sent);
    }

    public function test_an_order_cancelled_by_the_organizer_skips_attendees_who_had_cancelled_themselves(): void
    {
        $this->settingsRepository->shouldReceive('findFirstWhere')->andReturn($this->settings(true, self::TEMPLATE, true));
        $this->cancelledOrder([
            $this->attendee(1, cancelledByAttendee: true),
            $this->attendee(2, cancelledByAttendee: false),
            $this->attendee(3, cancelledByAttendee: false),
        ]);
        $this->phones([2, 3], [
            $this->withPhone(2, '+262639250207', 'Ali'),
            $this->withPhone(3, '+262639250208', 'Zaïna'),
        ]);

        $result = $this->service->sendForOrder(self::ORDER_ID);

        $this->assertSame(2, $result->sent);
        $this->assertSame([2, 3], array_column($this->claims, 'attendee_id'));
        $this->assertSame(['ORGANIZER', 'ORGANIZER'], array_column($this->claims, 'cancelled_by'));
        $this->assertStringContainsString('?c=code2', $this->sent[0]->content);
        $this->assertStringContainsString('?c=code3', $this->sent[1]->content);
    }

    public function test_an_order_that_is_not_cancelled_receives_nothing(): void
    {
        $this->orderRepository->shouldReceive('findFirst')->with(self::ORDER_ID)->andReturn(
            (new OrderDomainObject)->setId(self::ORDER_ID)->setEventId(self::EVENT_ID)->setStatus(OrderStatus::COMPLETED->name)
        );
        $this->attendeeRepository->shouldNotReceive('findWhere');

        $this->service->sendForOrder(self::ORDER_ID);

        $this->assertSame([], $this->sent);
    }

    public function test_an_attendee_is_never_sent_the_message_twice(): void
    {
        $this->settingsRepository->shouldReceive('findFirstWhere')->andReturn($this->settings(true, self::TEMPLATE, true));
        $this->confirmationRepository->shouldReceive('findFirstWhere')->with(['attendee_id' => 1])->andReturn(new SmsCancellationConfirmationDomainObject);
        $this->confirmationRepository->shouldReceive('findFirstWhere')->with(['attendee_id' => 2])->andReturnNull();
        $this->confirmationRepository->shouldReceive('createUnlessPhoneConfirmedSince')->with(Mockery::on(fn (array $attributes) => $attributes['attendee_id'] === 2), Mockery::any(), Mockery::any())
            ->andThrow(new UniqueConstraintViolationException('pgsql', 'insert', [], new \Exception('duplicate')));
        $this->cancelledOrder([$this->attendee(1, false), $this->attendee(2, false)]);
        $this->phones([1, 2], [
            $this->withPhone(1, '+262639250206', 'Awa'),
            $this->withPhone(2, '+262639250207', 'Ali'),
        ]);

        $result = $this->service->sendForOrder(self::ORDER_ID);

        $this->assertSame(2, $result->alreadySent);
        $this->assertSame([], $this->sent);
    }

    public function test_attendees_without_a_valid_phone_or_who_replied_stop_are_skipped(): void
    {
        $this->settingsRepository->shouldReceive('findFirstWhere')->andReturn($this->settings(true, self::TEMPLATE, true));
        $this->optOutRepository->shouldReceive('findFirstWhere')
            ->with(['organizer_id' => self::ORGANIZER_ID, 'phone' => '+262639250206'])
            ->andReturn(new SmsOptOutDomainObject);
        $this->optOutRepository->shouldReceive('findFirstWhere')->andReturnNull();
        $this->cancelledOrder([$this->attendee(1, false), $this->attendee(2, false), $this->attendee(3, false), $this->attendee(4, false)]);
        $this->phones([1, 2, 3, 4], [
            $this->withPhone(1, '+262639250206', 'Awa'),
            $this->withPhone(2, null, 'Ali'),
            $this->withPhone(3, '12', 'Zaïna'),
            $this->withPhone(4, '0639250209', null),
        ]);

        $result = $this->service->sendForOrder(self::ORDER_ID);

        $this->assertSame(1, $result->optedOut);
        $this->assertSame(2, $result->withoutValidPhone);
        $this->assertSame(1, $result->sent);
        $this->assertSame('+262639250209', $this->sent[0]->phone);
        $this->assertStringStartsWith("C'est noté, votre inscription", $this->sent[0]->content);
    }

    public function test_a_gateway_error_is_recorded_and_the_other_attendees_are_still_sent(): void
    {
        $this->settingsRepository->shouldReceive('findFirstWhere')->andReturn($this->settings(true, self::TEMPLATE, true));
        $this->sendService->shouldReceive('send')->once()->ordered()->andThrow(new SmsGatewayException('Téléphone hors ligne'));
        $this->sendService->shouldReceive('send')->once()->ordered()->andReturn((new SmsMessageDomainObject)->setId(777));
        $this->cancelledOrder([$this->attendee(1, false), $this->attendee(2, false)]);
        $this->phones([1, 2], [
            $this->withPhone(1, '+262639250206', 'Awa'),
            $this->withPhone(2, '+262639250207', 'Ali'),
        ]);

        $result = $this->service->sendForOrder(self::ORDER_ID);

        $this->assertSame(1, $result->failed);
        $this->assertSame(1, $result->sent);
        $this->assertSame(['status' => 'FAILED', 'failure_reason' => 'Téléphone hors ligne'], $this->updates[1]);
        $this->assertSame(['status' => 'SENT', 'sms_message_id' => 777], $this->updates[2]);
    }

    public function test_stops_when_the_sms_line_is_not_configured(): void
    {
        $this->settingsRepository->shouldReceive('findFirstWhere')->andReturn($this->settings(true, self::TEMPLATE, true));
        $this->sendService->shouldReceive('send')->once()->andThrow(new SmsGatewayNotConfiguredException('Non configuré'));
        $this->cancelledOrder([$this->attendee(1, false), $this->attendee(2, false)]);
        $this->phones([1, 2], [
            $this->withPhone(1, '+262639250206', 'Awa'),
            $this->withPhone(2, '+262639250207', 'Ali'),
        ]);

        $result = $this->service->sendForOrder(self::ORDER_ID);

        $this->assertSame(1, $result->failed);
        $this->assertSame('FAILED', $this->updates[1]['status']);
        $this->assertCount(1, $this->claims);
    }

    public function test_attendees_of_an_order_sharing_a_phone_receive_a_single_cancellation_sms(): void
    {
        $this->settingsRepository->shouldReceive('findFirstWhere')->andReturn($this->settings(true, self::TEMPLATE, true));
        $this->cancelledOrder([$this->attendee(1, false), $this->attendee(2, false), $this->attendee(3, false)]);
        $this->phones([1, 2, 3], [
            $this->withPhone(1, '0639 25 02 06', 'Awa'),
            $this->withPhone(2, '+262639250206', 'Ali'),
            $this->withPhone(3, '+262639250207', 'Zaïna'),
        ]);

        $result = $this->service->sendForOrder(self::ORDER_ID);

        $this->assertSame(2, $result->sent);
        $this->assertSame(1, $result->alreadyConfirmedForPhone);
        $this->assertSame(['+262639250206', '+262639250207'], array_map(fn (SendSmsDTO $sms) => $sms->phone, $this->sent));
        $this->assertSame('SKIPPED', $this->claims[2]['status']);
        $this->assertSame('Déjà confirmé pour ce numéro', $this->claims[2]['failure_reason']);
    }

    public function test_separate_cancellations_processed_together_send_once_per_phone(): void
    {
        $this->phones([1], [$this->withPhone(1, '+262639250206', 'Awa')]);
        $this->phones([2], [$this->withPhone(2, '0639250206', 'Ali')]);

        $first = $this->service->sendForAttendee(1);
        $second = $this->service->sendForAttendee(2);

        $this->assertSame(1, $first->sent);
        $this->assertSame(0, $second->sent);
        $this->assertSame(1, $second->alreadyConfirmedForPhone);
        $this->assertCount(1, $this->sent);
    }

    public function test_the_cancellation_wave_starts_ten_minutes_before_the_claim(): void
    {
        $this->phones([1], [$this->withPhone(1, '+262639250206', 'Awa')]);

        $this->service->sendForAttendee(1);

        $since = \Illuminate\Support\Carbon::parse($this->waveStarts[0]);
        $this->assertEqualsWithDelta(10 * 60, \Illuminate\Support\Carbon::now()->diffInSeconds($since, true), 5);
    }

    public function test_a_failed_cancellation_sms_does_not_block_the_number(): void
    {
        $this->settingsRepository->shouldReceive('findFirstWhere')->andReturn($this->settings(true, self::TEMPLATE, true));
        $this->sendService->shouldReceive('send')->once()->ordered()->andThrow(new SmsGatewayException('Téléphone hors ligne'));
        $this->sendService->shouldReceive('send')->once()->ordered()->andReturn((new SmsMessageDomainObject)->setId(777));
        $this->cancelledOrder([$this->attendee(1, false), $this->attendee(2, false)]);
        $this->phones([1, 2], [
            $this->withPhone(1, '+262639250206', 'Awa'),
            $this->withPhone(2, '+262639250206', 'Ali'),
        ]);

        $result = $this->service->sendForOrder(self::ORDER_ID);

        $this->assertSame(1, $result->failed);
        $this->assertSame(1, $result->sent);
    }

    public function test_enabled_rules(): void
    {
        $this->assertFalse(SmsCancellationConfirmationService::isEnabledFor(null, SmsCancellationSource::PARTICIPANT));
        $this->assertFalse(SmsCancellationConfirmationService::isEnabledFor($this->settings(false, null, true), SmsCancellationSource::PARTICIPANT));
        $this->assertTrue(SmsCancellationConfirmationService::isEnabledFor($this->settings(true, null, false), SmsCancellationSource::PARTICIPANT));
        $this->assertFalse(SmsCancellationConfirmationService::isEnabledFor($this->settings(true, null, false), SmsCancellationSource::ORGANIZER));
        $this->assertTrue(SmsCancellationConfirmationService::isEnabledFor($this->settings(true, null, true), SmsCancellationSource::ORGANIZER));
    }

    public function test_the_default_link_is_the_personal_attendance_link(): void
    {
        $attendanceLinks = Mockery::mock(SmsReminderPersonalLinkProviderInterface::class);
        $attendanceLinks->shouldReceive('findForAttendee')->with(5)->andReturn('https://ticket.picha.fr/venue/13/abc?code=x');
        $attendanceLinks->shouldReceive('findForAttendee')->with(6)->andReturnNull();
        $provider = new AttendanceSmsCancellationLinkProvider($attendanceLinks);

        $this->assertSame('https://ticket.picha.fr/venue/13/abc?code=x', $provider->findForAttendee(5, new EventDomainObject));
        $this->assertNull($provider->findForAttendee(6, new EventDomainObject));
    }

    /**
     * @param  int[]  $attendeeIds
     * @param  SmsReminderAttendeeDTO[]  $rows
     */
    private function phones(array $attendeeIds, array $rows): void
    {
        $this->directoryRepository->shouldReceive('findAttendeesWithPhone')
            ->with(self::EVENT_ID, $attendeeIds)
            ->andReturn(collect($rows));
    }

    /**
     * @param  AttendeeDomainObject[]  $attendees
     */
    private function cancelledOrder(array $attendees): void
    {
        $this->orderRepository->shouldReceive('findFirst')->with(self::ORDER_ID)->andReturn(
            (new OrderDomainObject)->setId(self::ORDER_ID)->setEventId(self::EVENT_ID)->setStatus(OrderStatus::CANCELLED->name)
        );
        $this->attendeeRepository->shouldReceive('findWhere')
            ->with(['order_id' => self::ORDER_ID, 'status' => 'CANCELLED'])
            ->andReturn(collect($attendees));
    }

    private function attendee(int $id, bool $cancelledByAttendee): AttendeeDomainObject
    {
        return (new AttendeeDomainObject)
            ->setId($id)
            ->setEventId(self::EVENT_ID)
            ->setOrderId(self::ORDER_ID)
            ->setStatus(AttendeeStatus::CANCELLED->name)
            ->setCancelledByAttendeeAt($cancelledByAttendee ? '2026-10-22 18:00:00' : null);
    }

    private function withPhone(int $id, ?string $phone, ?string $firstName): SmsReminderAttendeeDTO
    {
        return new SmsReminderAttendeeDTO(attendeeId: $id, phone: $phone, firstName: $firstName, lastName: null);
    }

    private function settings(bool $enabled, ?string $message, bool $onOrganizerCancel): EventSettingDomainObject
    {
        return (new EventSettingDomainObject)
            ->setEventId(self::EVENT_ID)
            ->setSmsCancellationConfirmationEnabled($enabled)
            ->setSmsCancellationConfirmationMessage($message)
            ->setSmsCancellationConfirmationOnOrganizerCancel($onOrganizerCancel);
    }
}
