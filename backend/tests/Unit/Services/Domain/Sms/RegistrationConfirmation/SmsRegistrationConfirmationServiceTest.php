<?php

namespace Tests\Unit\Services\Domain\Sms\RegistrationConfirmation;

use HiEvents\DomainObjects\Enums\SmsMessageAuthorType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\SmsMessageDomainObject;
use HiEvents\DomainObjects\SmsOptOutDomainObject;
use HiEvents\DomainObjects\SmsRegistrationConfirmationDomainObject;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Exceptions\SmsGatewayException;
use HiEvents\Exceptions\SmsGatewayNotConfiguredException;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsCustomerDirectoryRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsOptOutRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsRegistrationConfirmationRepositoryInterface;
use HiEvents\Services\Domain\Sms\DTO\SendSmsDTO;
use HiEvents\Services\Domain\Sms\DTO\SmsReminderAttendeeDTO;
use HiEvents\Services\Domain\Sms\RegistrationConfirmation\SmsRegistrationConfirmationService;
use HiEvents\Services\Domain\Sms\Reminder\SmsReminderMessageRenderer;
use HiEvents\Services\Domain\Sms\Reminder\SmsReminderPersonalLinkProviderInterface;
use HiEvents\Services\Domain\Sms\SmsSendService;
use Illuminate\Database\UniqueConstraintViolationException;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use Psr\Log\NullLogger;
use Tests\TestCase;

class SmsRegistrationConfirmationServiceTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const ORGANIZER_ID = 9;

    private const EVENT_ID = 8;

    private const ORDER_ID = 40;

    private OrderRepositoryInterface|MockInterface $orderRepository;

    private EventSettingsRepositoryInterface|MockInterface $settingsRepository;

    private SmsCustomerDirectoryRepositoryInterface|MockInterface $directoryRepository;

    private SmsOptOutRepositoryInterface|MockInterface $optOutRepository;

    private SmsRegistrationConfirmationRepositoryInterface|MockInterface $confirmationRepository;

    private SmsReminderPersonalLinkProviderInterface|MockInterface $linkProvider;

    private SmsSendService|MockInterface $sendService;

    private SmsRegistrationConfirmationService $service;

    /** @var SendSmsDTO[] */
    private array $sent = [];

    /** @var array<int, array> */
    private array $claims = [];

    /** @var array<int, array> */
    private array $updates = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->orderRepository = Mockery::mock(OrderRepositoryInterface::class);
        $this->settingsRepository = Mockery::mock(EventSettingsRepositoryInterface::class);
        $eventRepository = Mockery::mock(EventRepositoryInterface::class);
        $this->directoryRepository = Mockery::mock(SmsCustomerDirectoryRepositoryInterface::class);
        $this->optOutRepository = Mockery::mock(SmsOptOutRepositoryInterface::class);
        $this->confirmationRepository = Mockery::mock(SmsRegistrationConfirmationRepositoryInterface::class);
        $this->linkProvider = Mockery::mock(SmsReminderPersonalLinkProviderInterface::class);
        $this->sendService = Mockery::mock(SmsSendService::class);

        $this->orderRepository->shouldReceive('findFirst')->with(self::ORDER_ID)->andReturn($this->order())->byDefault();
        $this->settingsRepository->shouldReceive('findFirstWhere')->with(['event_id' => self::EVENT_ID])->andReturn(
            $this->settings(true, 'Bonjour {prenom}, votre place pour {evenement} le {date} à {heure} est réservée. Gérer ma venue : {lien}')
        )->byDefault();
        $eventRepository->shouldReceive('findFirstWhere')->with(['id' => self::EVENT_ID])->andReturn(
            (new EventDomainObject)
                ->setId(self::EVENT_ID)
                ->setOrganizerId(self::ORGANIZER_ID)
                ->setTitle('Konka Wawa')
                ->setStartDate('2026-10-24 17:00:00')
                ->setTimezone('Indian/Mayotte')
        );
        $this->optOutRepository->shouldReceive('findFirstWhere')->andReturnNull()->byDefault();
        $this->linkProvider->shouldReceive('findForAttendee')->andReturnUsing(
            fn (int $attendeeId) => 'https://ticket.picha.fr/venue/8/a'.$attendeeId.'?code=x'
        )->byDefault();

        $this->confirmationRepository->shouldReceive('findFirstWhere')->andReturnNull()->byDefault();
        $this->confirmationRepository->shouldReceive('create')->andReturnUsing(function (array $attributes) {
            $id = count($this->claims) + 1;
            $this->claims[$id] = $attributes;

            return (new SmsRegistrationConfirmationDomainObject)->setId($id)->setAttendeeId($attributes['attendee_id']);
        })->byDefault();
        $this->confirmationRepository->shouldReceive('updateFromArray')->andReturnUsing(function (int $id, array $attributes) {
            $this->updates[$id] = $attributes;

            return new SmsRegistrationConfirmationDomainObject;
        })->byDefault();

        $this->sendService->shouldReceive('send')->andReturnUsing(function (SendSmsDTO $sms) {
            $this->sent[] = $sms;

            return (new SmsMessageDomainObject)->setId(500 + count($this->sent));
        })->byDefault();

        $this->service = new SmsRegistrationConfirmationService(
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
        );
    }

    public function test_sends_the_message_with_the_variables_and_the_personal_link(): void
    {
        $this->attendees([$this->attendee(1, '0639 25 02 06', 'Awa')]);

        $result = $this->service->sendForOrder(self::ORDER_ID);

        $this->assertSame(1, $result->sent);
        $this->assertCount(1, $this->sent);
        $this->assertSame(self::ORGANIZER_ID, $this->sent[0]->organizerId);
        $this->assertSame('+262639250206', $this->sent[0]->phone);
        $this->assertSame(SmsMessageAuthorType::AUTOMATIC, $this->sent[0]->authorType);
        $this->assertSame(
            'Bonjour Awa, votre place pour Konka Wawa le samedi 24 octobre à 20h00 est réservée. Gérer ma venue : https://ticket.picha.fr/venue/8/a1?code=x',
            $this->sent[0]->content,
        );
        $this->assertSame([
            'organizer_id' => self::ORGANIZER_ID,
            'event_id' => self::EVENT_ID,
            'order_id' => self::ORDER_ID,
            'attendee_id' => 1,
            'phone' => '+262639250206',
            'status' => 'SENDING',
        ], $this->claims[1]);
        $this->assertSame(['status' => 'SENT', 'sms_message_id' => 501], $this->updates[1]);
    }

    public function test_the_link_and_its_label_are_removed_when_there_is_no_personal_link(): void
    {
        $this->linkProvider->shouldReceive('findForAttendee')->andReturnNull();
        $this->attendees([$this->attendee(1, '+262639250206', 'Awa')]);

        $this->service->sendForOrder(self::ORDER_ID);

        $this->assertSame(
            'Bonjour Awa, votre place pour Konka Wawa le samedi 24 octobre à 20h00 est réservée.',
            $this->sent[0]->content,
        );
        $this->assertStringNotContainsString('http', $this->sent[0]->content);
    }

    public function test_uses_the_default_message_when_the_text_is_empty(): void
    {
        $this->settingsRepository->shouldReceive('findFirstWhere')->andReturn($this->settings(true, null));
        $this->attendees([$this->attendee(1, '+262639250206', 'Awa')]);

        $this->service->sendForOrder(self::ORDER_ID);

        $this->assertSame(
            "C'est validé, votre place pour Konka Wawa est réservée. Rendez-vous le samedi 24 octobre, accueil dès 20h00. Gérer ma venue : https://ticket.picha.fr/venue/8/a1?code=x",
            $this->sent[0]->content,
        );
    }

    public function test_nothing_is_sent_when_the_option_is_disabled(): void
    {
        $this->settingsRepository->shouldReceive('findFirstWhere')->andReturn($this->settings(false, 'Bonjour'));
        $this->directoryRepository->shouldNotReceive('findActiveAttendeesWithPhone');

        $result = $this->service->sendForOrder(self::ORDER_ID);

        $this->assertSame(0, $result->sent);
        $this->assertSame([], $this->sent);
    }

    public function test_box_office_and_manual_orders_receive_nothing(): void
    {
        $this->orderRepository->shouldReceive('findFirst')->andReturn($this->order()->setIsManuallyCreated(true));
        $this->directoryRepository->shouldNotReceive('findActiveAttendeesWithPhone');

        $this->service->sendForOrder(self::ORDER_ID);

        $this->assertSame([], $this->sent);
    }

    public function test_orders_that_are_not_completed_receive_nothing(): void
    {
        $this->orderRepository->shouldReceive('findFirst')->andReturn($this->order()->setStatus(OrderStatus::AWAITING_OFFLINE_PAYMENT->name));
        $this->directoryRepository->shouldNotReceive('findActiveAttendeesWithPhone');

        $this->service->sendForOrder(self::ORDER_ID);

        $this->assertSame([], $this->sent);
    }

    public function test_an_attendee_already_confirmed_is_never_sent_twice(): void
    {
        $this->confirmationRepository->shouldReceive('findFirstWhere')->with(['attendee_id' => 1])->andReturn(new SmsRegistrationConfirmationDomainObject);
        $this->confirmationRepository->shouldReceive('findFirstWhere')->with(['attendee_id' => 2])->andReturnNull();
        $this->confirmationRepository->shouldReceive('create')->with(Mockery::on(fn (array $attributes) => $attributes['attendee_id'] === 2))
            ->andThrow(new UniqueConstraintViolationException('pgsql', 'insert', [], new \Exception('duplicate')));
        $this->attendees([
            $this->attendee(1, '+262639250206', 'Awa'),
            $this->attendee(2, '+262639250207', 'Ali'),
        ]);

        $result = $this->service->sendForOrder(self::ORDER_ID);

        $this->assertSame(2, $result->alreadySent);
        $this->assertSame([], $this->sent);
    }

    public function test_numbers_that_replied_stop_to_this_organizer_are_skipped(): void
    {
        $this->optOutRepository->shouldReceive('findFirstWhere')
            ->with(['organizer_id' => self::ORGANIZER_ID, 'phone' => '+262639250206'])
            ->andReturn(new SmsOptOutDomainObject);
        $this->optOutRepository->shouldReceive('findFirstWhere')->andReturnNull();
        $this->attendees([
            $this->attendee(1, '+262639250206', 'Awa'),
            $this->attendee(2, '+262639250207', 'Ali'),
        ]);

        $result = $this->service->sendForOrder(self::ORDER_ID);

        $this->assertSame(1, $result->optedOut);
        $this->assertSame(1, $result->sent);
        $this->assertSame('+262639250207', $this->sent[0]->phone);
        $this->assertArrayNotHasKey(2, $this->claims);
    }

    public function test_attendees_without_a_valid_phone_are_skipped(): void
    {
        $this->attendees([
            $this->attendee(1, null, 'Awa'),
            $this->attendee(2, '12', 'Ali'),
            $this->attendee(3, '0639250208', 'Zaïna'),
        ]);

        $result = $this->service->sendForOrder(self::ORDER_ID);

        $this->assertSame(2, $result->withoutValidPhone);
        $this->assertSame(1, $result->sent);
        $this->assertSame('+262639250208', $this->sent[0]->phone);
        $this->assertSame([3], array_column($this->claims, 'attendee_id'));
    }

    public function test_each_attendee_receives_their_own_link(): void
    {
        $this->attendees([
            $this->attendee(1, '+262639250206', 'Awa'),
            $this->attendee(2, '+262639250206', 'Ali'),
            $this->attendee(3, '+262639250207', null),
        ]);

        $result = $this->service->sendForOrder(self::ORDER_ID);

        $this->assertSame(3, $result->sent);
        $this->assertStringContainsString('Bonjour Awa,', $this->sent[0]->content);
        $this->assertStringContainsString('/a1?code=x', $this->sent[0]->content);
        $this->assertStringContainsString('Bonjour Ali,', $this->sent[1]->content);
        $this->assertStringContainsString('/a2?code=x', $this->sent[1]->content);
        $this->assertStringStartsWith('Bonjour, votre place', $this->sent[2]->content);
        $this->assertStringContainsString('/a3?code=x', $this->sent[2]->content);
    }

    public function test_a_gateway_error_is_recorded_and_the_other_attendees_are_still_sent(): void
    {
        $this->sendService->shouldReceive('send')->once()->ordered()->andThrow(new SmsGatewayException('Téléphone hors ligne'));
        $this->sendService->shouldReceive('send')->once()->ordered()->andReturn((new SmsMessageDomainObject)->setId(777));
        $this->attendees([
            $this->attendee(1, '+262639250206', 'Awa'),
            $this->attendee(2, '+262639250207', 'Ali'),
        ]);

        $result = $this->service->sendForOrder(self::ORDER_ID);

        $this->assertSame(1, $result->failed);
        $this->assertSame(1, $result->sent);
        $this->assertSame(['status' => 'FAILED', 'failure_reason' => 'Téléphone hors ligne'], $this->updates[1]);
        $this->assertSame(['status' => 'SENT', 'sms_message_id' => 777], $this->updates[2]);
    }

    public function test_stops_when_the_sms_line_is_not_configured(): void
    {
        $this->sendService->shouldReceive('send')->once()->andThrow(new SmsGatewayNotConfiguredException('Non configuré'));
        $this->attendees([
            $this->attendee(1, '+262639250206', 'Awa'),
            $this->attendee(2, '+262639250207', 'Ali'),
        ]);

        $result = $this->service->sendForOrder(self::ORDER_ID);

        $this->assertSame(1, $result->failed);
        $this->assertSame('FAILED', $this->updates[1]['status']);
        $this->assertCount(1, $this->claims);
    }

    public function test_order_eligibility(): void
    {
        $this->assertTrue(SmsRegistrationConfirmationService::isEligibleOrder($this->order()));
        $this->assertFalse(SmsRegistrationConfirmationService::isEligibleOrder($this->order()->setIsManuallyCreated(true)));
        $this->assertFalse(SmsRegistrationConfirmationService::isEligibleOrder($this->order()->setStatus(OrderStatus::RESERVED->name)));
    }

    private function attendees(array $attendees): void
    {
        $this->directoryRepository->shouldReceive('findActiveAttendeesWithPhone')
            ->with(self::EVENT_ID, self::ORDER_ID)
            ->andReturn(collect($attendees));
    }

    private function attendee(int $id, ?string $phone, ?string $firstName): SmsReminderAttendeeDTO
    {
        return new SmsReminderAttendeeDTO(attendeeId: $id, phone: $phone, firstName: $firstName, lastName: null);
    }

    private function order(): OrderDomainObject
    {
        return (new OrderDomainObject)
            ->setId(self::ORDER_ID)
            ->setEventId(self::EVENT_ID)
            ->setStatus(OrderStatus::COMPLETED->name)
            ->setIsManuallyCreated(false);
    }

    private function settings(bool $enabled, ?string $message): EventSettingDomainObject
    {
        return (new EventSettingDomainObject)
            ->setEventId(self::EVENT_ID)
            ->setSmsRegistrationConfirmationEnabled($enabled)
            ->setSmsRegistrationConfirmationMessage($message);
    }
}
