<?php

namespace Tests\Unit\Services\Domain\Sms\RegistrationConfirmation;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\Enums\CustomerAddressForm;
use HiEvents\DomainObjects\Enums\SmsMessageAuthorType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\SmsMessageDomainObject;
use HiEvents\DomainObjects\SmsOptOutDomainObject;
use HiEvents\DomainObjects\SmsRegistrationConfirmationDomainObject;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Exceptions\SmsGatewayException;
use HiEvents\Exceptions\SmsGatewayNotConfiguredException;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
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
use HiEvents\Services\Domain\Sms\SmsShortLinkService;
use HiEvents\Services\Domain\Sms\SmsWithLinkSender;
use Illuminate\Database\UniqueConstraintViolationException;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use Psr\Log\NullLogger;
use Tests\TestCase;
use Tests\Unit\Support\StaticCustomerLocaleResolver;

class SmsRegistrationConfirmationServiceTest extends TestCase
{
    private StaticCustomerLocaleResolver $customerLocaleResolver;

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

    private AttendeeRepositoryInterface|MockInterface $attendeeRepository;

    private SmsRegistrationConfirmationService $service;

    /** @var SendSmsDTO[] text SMS */
    private array $sent = [];

    /** @var SendSmsDTO[] link SMS */
    private array $links = [];

    /** @var string[] every SMS content, in sending order */
    private array $outbox = [];

    private SmsShortLinkService|MockInterface $shortLinkService;

    /** @var array<int, array> */
    private array $claims = [];

    /** @var array<int, array> */
    private array $updates = [];

    /** @var array<int, array> */
    private array $rows = [];

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
        $this->attendeeRepository = Mockery::mock(AttendeeRepositoryInterface::class);
        $this->attendeeRepository->shouldReceive('findFirst')->andReturnUsing(
            fn (int $id) => (new AttendeeDomainObject)->setId($id)->setStatus(AttendeeStatus::ACTIVE->name)
        )->byDefault();

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

        $this->confirmationRepository->shouldReceive('findFirstWhere')->andReturnUsing(
            fn (array $where) => $this->findStoredConfirmation($where)
        )->byDefault();
        $this->confirmationRepository->shouldReceive('create')->andReturnUsing(function (array $attributes) {
            if (in_array($attributes['status'], ['SENDING', 'SENT'], true)
                && $this->activeHolder($attributes['event_id'], $attributes['phone']) !== null) {
                throw new UniqueConstraintViolationException('pgsql', 'insert', [], new \Exception('sms_registration_confirmations_one_per_phone'));
            }

            $id = count($this->claims) + 1;
            $this->claims[$id] = $attributes;
            $this->rows[$id] = $attributes;

            return (new SmsRegistrationConfirmationDomainObject)->setId($id)->setAttendeeId($attributes['attendee_id']);
        })->byDefault();
        $this->confirmationRepository->shouldReceive('updateFromArray')->andReturnUsing(function (int $id, array $attributes) {
            $this->updates[$id] = $attributes;
            $this->rows[$id] = array_merge($this->rows[$id] ?? [], $attributes);

            return new SmsRegistrationConfirmationDomainObject;
        })->byDefault();

        $this->sendService->shouldReceive('send')->andReturnUsing(function (SendSmsDTO $sms) {
            $this->outbox[] = $sms->content;
            if (str_starts_with($sms->content, 'https://ticket.picha.fr/q/')) {
                $this->links[] = $sms;

                return (new SmsMessageDomainObject)->setId(900 + count($this->links));
            }
            $this->sent[] = $sms;

            return (new SmsMessageDomainObject)->setId(500 + count($this->sent));
        })->byDefault();

        $this->shortLinkService = Mockery::mock(SmsShortLinkService::class);
        $this->shortLinkService->shouldReceive('shorten')->andReturnUsing(
            fn (EventDomainObject $event, string $url) => 'https://ticket.picha.fr/q/'.(preg_match('~/(a\d+)\?~', $url, $m) ? $m[1] : 'x')
        )->byDefault();

        $this->service = new SmsRegistrationConfirmationService(
            $this->orderRepository,
            $eventRepository,
            $this->settingsRepository,
            $this->directoryRepository,
            $this->optOutRepository,
            $this->confirmationRepository,
            new SmsReminderMessageRenderer,
            $this->linkProvider,
            new SmsWithLinkSender($this->sendService, new NullLogger),
            new NullLogger,
            $this->customerLocaleResolver = new StaticCustomerLocaleResolver,
            $this->attendeeRepository,
            $this->shortLinkService,
        );
    }

    public function test_sends_the_text_then_the_short_link_alone_in_a_second_sms(): void
    {
        $this->shortLinkService->shouldReceive('shorten')
            ->once()
            ->withArgs(fn (EventDomainObject $event, string $url) => $event->getId() === self::EVENT_ID && $url === 'https://ticket.picha.fr/venue/8/a1?code=x')
            ->andReturn('https://ticket.picha.fr/q/a1');
        $this->attendees([$this->attendee(1, '0639 25 02 06', 'Awa')]);

        $result = $this->service->sendForOrder(self::ORDER_ID);

        $this->assertSame(1, $result->sent);
        $this->assertSame([
            'Bonjour Awa, votre place pour Konka Wawa le samedi 24 octobre à 20h00 est réservée. Gérer ma venue : lien ci-dessous',
            'https://ticket.picha.fr/q/a1',
        ], $this->outbox);
        $this->assertSame(self::ORGANIZER_ID, $this->sent[0]->organizerId);
        $this->assertSame('+262639250206', $this->sent[0]->phone);
        $this->assertSame(SmsMessageAuthorType::AUTOMATIC, $this->sent[0]->authorType);
        $this->assertSame('+262639250206', $this->links[0]->phone);
        $this->assertSame(SmsMessageAuthorType::AUTOMATIC, $this->links[0]->authorType);
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
        $this->assertSame([], $this->links);
    }

    public function test_uses_the_default_message_when_the_text_is_empty(): void
    {
        $this->settingsRepository->shouldReceive('findFirstWhere')->andReturn($this->settings(true, null));
        $this->attendees([$this->attendee(1, '+262639250206', 'Awa')]);

        $this->service->sendForOrder(self::ORDER_ID);

        $this->assertSame(
            "C'est validé, votre place pour Konka Wawa est réservée. Rendez-vous le samedi 24 octobre, accueil dès 20h00. Gérer ma venue : lien ci-dessous",
            $this->sent[0]->content,
        );
        $this->assertSame('https://ticket.picha.fr/q/a1', $this->links[0]->content);
    }

    public function test_the_default_message_says_tu_when_the_organizer_chose_it(): void
    {
        $this->customerLocaleResolver->addressForm = CustomerAddressForm::TU;
        $this->settingsRepository->shouldReceive('findFirstWhere')->andReturn($this->settings(true, null));
        $this->attendees([$this->attendee(1, '+262639250206', 'Awa')]);

        $this->service->sendForOrder(self::ORDER_ID);

        $this->assertSame(
            "C'est validé, ta place pour Konka Wawa est réservée. Rendez-vous le samedi 24 octobre, accueil dès 20h00. Gérer ma venue : lien ci-dessous",
            $this->sent[0]->content,
        );
        $this->assertSame('https://ticket.picha.fr/q/a1', $this->links[0]->content);
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
            $this->attendee(2, '+262639250208', 'Ali'),
            $this->attendee(3, '+262639250207', null),
        ]);

        $result = $this->service->sendForOrder(self::ORDER_ID);

        $this->assertSame(3, $result->sent);
        $this->assertStringContainsString('Bonjour Awa,', $this->outbox[0]);
        $this->assertSame('https://ticket.picha.fr/q/a1', $this->outbox[1]);
        $this->assertStringContainsString('Bonjour Ali,', $this->outbox[2]);
        $this->assertSame('https://ticket.picha.fr/q/a2', $this->outbox[3]);
        $this->assertStringStartsWith('Bonjour, votre place', $this->outbox[4]);
        $this->assertSame('https://ticket.picha.fr/q/a3', $this->outbox[5]);
    }

    public function test_a_gateway_error_is_recorded_and_the_other_attendees_are_still_sent(): void
    {
        $calls = [];
        $this->sendService->shouldReceive('send')->andReturnUsing(function (SendSmsDTO $sms) use (&$calls) {
            $calls[] = $sms->content;
            if (count($calls) === 1) {
                throw new SmsGatewayException('Téléphone hors ligne');
            }

            return (new SmsMessageDomainObject)->setId(count($calls) === 2 ? 777 : 778);
        });
        $this->attendees([
            $this->attendee(1, '+262639250206', 'Awa'),
            $this->attendee(2, '+262639250207', 'Ali'),
        ]);

        $result = $this->service->sendForOrder(self::ORDER_ID);

        $this->assertSame(1, $result->failed);
        $this->assertSame(1, $result->sent);
        $this->assertSame(['status' => 'FAILED', 'failure_reason' => 'Téléphone hors ligne'], $this->updates[1]);
        $this->assertSame(['status' => 'SENT', 'sms_message_id' => 777], $this->updates[2]);
        $this->assertCount(3, $calls);
        $this->assertStringStartsWith('Bonjour Awa,', $calls[0]);
        $this->assertStringStartsWith('Bonjour Ali,', $calls[1]);
        $this->assertSame('https://ticket.picha.fr/q/a2', $calls[2]);
    }

    public function test_a_failed_link_sms_does_not_fail_the_confirmation(): void
    {
        $calls = [];
        $this->sendService->shouldReceive('send')->andReturnUsing(function (SendSmsDTO $sms) use (&$calls) {
            $calls[] = $sms->content;
            if (count($calls) === 2) {
                throw new SmsGatewayException('Téléphone hors ligne');
            }

            return (new SmsMessageDomainObject)->setId(700 + count($calls));
        });
        $this->attendees([$this->attendee(1, '+262639250206', 'Awa')]);

        $result = $this->service->sendForOrder(self::ORDER_ID);

        $this->assertSame(1, $result->sent);
        $this->assertSame(['status' => 'SENT', 'sms_message_id' => 701], $this->updates[1]);
        $this->assertSame('https://ticket.picha.fr/q/a1', $calls[1]);
    }

    public function test_a_template_without_the_link_variable_sends_a_single_sms(): void
    {
        $this->settingsRepository->shouldReceive('findFirstWhere')->andReturn($this->settings(true, 'Bonjour {prenom}, à bientôt.'));
        $this->linkProvider->shouldNotReceive('findForAttendee');
        $this->shortLinkService->shouldNotReceive('shorten');
        $this->attendees([$this->attendee(1, '+262639250206', 'Awa')]);

        $this->service->sendForOrder(self::ORDER_ID);

        $this->assertSame(['Bonjour Awa, à bientôt.'], $this->outbox);
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

    public function test_two_attendees_with_the_same_phone_receive_a_single_confirmation(): void
    {
        $this->attendees([
            $this->attendee(1, '0639 25 02 06', 'Awa'),
            $this->attendee(2, '+262639250206', 'Ali'),
        ]);

        $result = $this->service->sendForOrder(self::ORDER_ID);

        $this->assertSame(1, $result->sent);
        $this->assertSame(1, $result->alreadyConfirmedForPhone);
        $this->assertCount(1, $this->sent);
        $this->assertStringContainsString('Bonjour Awa,', $this->sent[0]->content);
        $this->assertSame('SKIPPED', $this->claims[2]['status']);
        $this->assertSame(2, $this->claims[2]['attendee_id']);
        $this->assertSame('Déjà confirmé pour ce numéro', $this->claims[2]['failure_reason']);
    }

    public function test_a_number_confirmed_by_an_earlier_order_is_not_confirmed_again(): void
    {
        $this->storeConfirmation(attendeeId: 70, eventId: self::EVENT_ID, phone: '+262639250206', status: 'SENT');
        $this->attendees([$this->attendee(1, '0639250206', 'Awa')]);

        $result = $this->service->sendForOrder(self::ORDER_ID);

        $this->assertSame(0, $result->sent);
        $this->assertSame(1, $result->alreadyConfirmedForPhone);
        $this->assertSame([], $this->sent);
    }

    public function test_the_same_number_is_confirmed_again_for_another_event(): void
    {
        $this->storeConfirmation(attendeeId: 70, eventId: 99, phone: '+262639250206', status: 'SENT');
        $this->attendees([$this->attendee(1, '+262639250206', 'Awa')]);

        $result = $this->service->sendForOrder(self::ORDER_ID);

        $this->assertSame(1, $result->sent);
        $this->assertSame(0, $result->alreadyConfirmedForPhone);
    }

    public function test_a_failed_confirmation_does_not_block_the_number(): void
    {
        $this->storeConfirmation(attendeeId: 70, eventId: self::EVENT_ID, phone: '+262639250206', status: 'FAILED');
        $this->attendees([$this->attendee(1, '+262639250206', 'Awa')]);

        $this->assertSame(1, $this->service->sendForOrder(self::ORDER_ID)->sent);
    }

    public function test_a_concurrent_order_that_took_the_number_first_wins(): void
    {
        $this->confirmationRepository->shouldReceive('findFirstWhere')->andReturnNull();
        $this->confirmationRepository->shouldReceive('create')
            ->with(Mockery::on(fn (array $attributes) => $attributes['status'] === 'SENDING'))
            ->andThrow(new UniqueConstraintViolationException('pgsql', 'insert', [], new \Exception('sms_registration_confirmations_one_per_phone')));
        $this->attendees([$this->attendee(1, '+262639250206', 'Awa')]);

        $result = $this->service->sendForOrder(self::ORDER_ID);

        $this->assertSame([], $this->sent);
        $this->assertSame(1, $result->alreadyConfirmedForPhone);
        $this->assertSame('SKIPPED', $this->claims[1]['status']);
    }

    public function test_a_new_registration_after_a_cancellation_is_confirmed_again(): void
    {
        $holderId = $this->storeConfirmation(attendeeId: 70, eventId: self::EVENT_ID, phone: '+262639250206', status: 'SENT');
        $this->attendeeRepository->shouldReceive('findFirst')->with(70)->andReturn(
            (new AttendeeDomainObject)->setId(70)->setStatus(AttendeeStatus::CANCELLED->name)
        );
        $this->attendees([$this->attendee(1, '+262639250206', 'Awa')]);

        $result = $this->service->sendForOrder(self::ORDER_ID);

        $this->assertSame(1, $result->sent);
        $this->assertArrayHasKey('phone_released_at', $this->updates[$holderId]);
        $this->assertSame('SENT', $this->rows[$holderId + 1]['status']);
    }

    public function test_the_number_stays_taken_while_the_confirmed_attendee_is_registered(): void
    {
        $holderId = $this->storeConfirmation(attendeeId: 70, eventId: self::EVENT_ID, phone: '+262639250206', status: 'SENT');
        $this->attendees([$this->attendee(1, '+262639250206', 'Awa')]);

        $this->service->sendForOrder(self::ORDER_ID);

        $this->assertSame([], $this->sent);
        $this->assertArrayNotHasKey($holderId, $this->updates);
    }

    public function test_order_eligibility(): void
    {
        $this->assertTrue(SmsRegistrationConfirmationService::isEligibleOrder($this->order()));
        $this->assertFalse(SmsRegistrationConfirmationService::isEligibleOrder($this->order()->setIsManuallyCreated(true)));
        $this->assertFalse(SmsRegistrationConfirmationService::isEligibleOrder($this->order()->setStatus(OrderStatus::RESERVED->name)));
    }

    private function storeConfirmation(int $attendeeId, int $eventId, string $phone, string $status): int
    {
        $id = count($this->claims) + 1;
        $this->claims[$id] = $this->rows[$id] = [
            'event_id' => $eventId,
            'attendee_id' => $attendeeId,
            'phone' => $phone,
            'status' => $status,
        ];

        return $id;
    }

    private function findStoredConfirmation(array $where): ?SmsRegistrationConfirmationDomainObject
    {
        if (array_keys($where) === ['attendee_id']) {
            foreach ($this->rows as $id => $claim) {
                if ($claim['attendee_id'] === $where['attendee_id']) {
                    return (new SmsRegistrationConfirmationDomainObject)->setId($id)->setAttendeeId($claim['attendee_id']);
                }
            }

            return null;
        }

        return $this->activeHolder($where['event_id'], $where['phone']);
    }

    private function activeHolder(int $eventId, string $phone): ?SmsRegistrationConfirmationDomainObject
    {
        foreach ($this->rows as $id => $claim) {
            if ($claim['event_id'] === $eventId
                && $claim['phone'] === $phone
                && in_array($claim['status'], ['SENDING', 'SENT'], true)
                && ($claim['phone_released_at'] ?? null) === null) {
                return (new SmsRegistrationConfirmationDomainObject)->setId($id)->setAttendeeId($claim['attendee_id']);
            }
        }

        return null;
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
