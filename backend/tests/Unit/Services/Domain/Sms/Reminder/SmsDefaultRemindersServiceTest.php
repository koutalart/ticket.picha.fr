<?php

namespace Tests\Unit\Services\Domain\Sms\Reminder;

use HiEvents\DomainObjects\Enums\CustomerAddressForm;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\OrganizerSettingDomainObject;
use HiEvents\DomainObjects\SmsCampaignDomainObject;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerSettingsRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsCampaignRecipientRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsCampaignRepositoryInterface;
use HiEvents\Services\Domain\Customer\CustomerLocaleResolver;
use HiEvents\Services\Domain\Sms\Reminder\SmsDefaultRemindersService;
use HiEvents\Services\Domain\Sms\Reminder\SmsReminderMessageRenderer;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Mockery;
use Mockery\MockInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Tests\TestCase;

class SmsDefaultRemindersServiceTest extends TestCase
{
    private OrganizerSettingsRepositoryInterface|MockInterface $organizerSettingsRepository;

    private SmsCampaignRepositoryInterface|MockInterface $campaignRepository;

    private SmsCampaignRecipientRepositoryInterface|MockInterface $recipientRepository;

    private CustomerLocaleResolver|MockInterface $customerLocaleResolver;

    private LoggerInterface|MockInterface $logger;

    private SmsDefaultRemindersService $service;

    /** @var array<int, array> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-22 06:00:00');

        $organizerRepository = Mockery::mock(OrganizerRepositoryInterface::class);
        $organizerRepository->shouldReceive('findFirstWhere')->with(['id' => 9])
            ->andReturn((new OrganizerDomainObject)->setId(9)->setAccountId(3)->setName('Konka Wawa'));

        $this->organizerSettingsRepository = Mockery::mock(OrganizerSettingsRepositoryInterface::class);
        $this->campaignRepository = Mockery::mock(SmsCampaignRepositoryInterface::class);
        $this->recipientRepository = Mockery::mock(SmsCampaignRecipientRepositoryInterface::class);
        $this->customerLocaleResolver = Mockery::mock(CustomerLocaleResolver::class);
        $this->customerLocaleResolver->shouldReceive('addressFormForOrganizerId')->with(9)->andReturn(CustomerAddressForm::VOUS)->byDefault();
        $this->logger = Mockery::mock(LoggerInterface::class);
        $databaseManager = Mockery::mock(DatabaseManager::class);
        $databaseManager->shouldReceive('afterCommit')->andReturnUsing(fn (callable $callback) => $callback());

        $this->campaignRepository->shouldReceive('create')->andReturnUsing(function (array $attributes) {
            $this->created[] = $attributes;

            return (new SmsCampaignDomainObject)->setId(100 + count($this->created))->setAutoKey($attributes['auto_key']);
        })->byDefault();

        $this->service = new SmsDefaultRemindersService(
            $organizerRepository,
            $this->organizerSettingsRepository,
            $this->campaignRepository,
            $this->recipientRepository,
            $this->customerLocaleResolver,
            $this->logger,
            $databaseManager,
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_creates_the_three_reminders_in_the_event_timezone(): void
    {
        $this->enabled(true);
        $this->noExistingReminder();

        $created = $this->service->createForEvent($this->event('2026-11-07 17:00:00'));

        $this->assertCount(3, $created);
        $this->assertSame(['J-7', 'J-1', 'J'], array_column($this->created, 'auto_key'));
        $this->assertSame(
            ['2026-10-31 15:00:00', '2026-11-06 15:00:00', '2026-11-07 14:00:00'],
            array_column($this->created, 'scheduled_at'),
        );
        $this->assertSame(['Rappel J-7', 'Rappel J-1', 'Rappel jour J'], array_column($this->created, 'name'));

        foreach ($this->created as $attributes) {
            $this->assertSame('REMINDER', $attributes['type']);
            $this->assertSame('SCHEDULED', $attributes['status']);
            $this->assertSame(8, $attributes['event_id']);
            $this->assertSame(9, $attributes['organizer_id']);
            $this->assertSame(3, $attributes['account_id']);
            $this->assertFalse($attributes['auto_edited']);
            $this->assertDoesNotMatchRegularExpression('/[•·—–]/u', $attributes['message']);
        }
    }

    public function test_texts_in_vous(): void
    {
        $this->enabled(true);
        $this->noExistingReminder();

        $this->service->createForEvent($this->event('2026-11-07 17:00:00'));

        $this->assertSame([
            "{prenom}, plus qu'une semaine avant {evenement} le {date} à {heure}. Gérer ma venue : {lien}\n\nKonka Wawa via PICHA Ticket",
            "{prenom}, c'est demain ! {evenement} le {date} à {heure}. Gérer ma venue : {lien}\n\nKonka Wawa via PICHA Ticket",
            "{prenom}, c'est aujourd'hui ! {evenement} à {heure}. À tout à l'heure !\n\nKonka Wawa via PICHA Ticket",
        ], array_column($this->created, 'message'));
    }

    public function test_texts_in_tu(): void
    {
        $this->enabled(true);
        $this->noExistingReminder();
        $this->customerLocaleResolver->shouldReceive('addressFormForOrganizerId')->with(9)->andReturn(CustomerAddressForm::TU);

        $this->service->createForEvent($this->event('2026-11-07 17:00:00'));

        $this->assertSame([
            "{prenom}, plus qu'une semaine avant {evenement} le {date} à {heure}. Gère ta venue : {lien}\n\nKonka Wawa via PICHA Ticket",
            "{prenom}, c'est demain ! {evenement} le {date} à {heure}. Gère ta venue : {lien}\n\nKonka Wawa via PICHA Ticket",
            "{prenom}, c'est aujourd'hui ! {evenement} à {heure}. À tout à l'heure !\n\nKonka Wawa via PICHA Ticket",
        ], array_column($this->created, 'message'));
    }

    public function test_rendered_text_reads_well(): void
    {
        $message = SmsDefaultRemindersService::message('J-1', CustomerAddressForm::TU, 'Konka Wawa');

        $this->assertSame(
            "Awa, c'est demain ! Konka Wawa le samedi 7 novembre à 20h00. Gère ta venue : https://ticket.picha.fr/c/abc\n\nKonka Wawa via PICHA Ticket",
            (new SmsReminderMessageRenderer)->render($message, $this->event('2026-11-07 17:00:00'), 'Awa', 'https://ticket.picha.fr/c/abc'),
        );
    }

    public function test_skips_the_reminders_already_past(): void
    {
        $this->enabled(true);
        $this->noExistingReminder();

        $this->service->createForEvent($this->event('2026-10-25 17:00:00'));

        $this->assertSame(['J-1', 'J'], array_column($this->created, 'auto_key'));
        $this->assertSame(['2026-10-24 15:00:00', '2026-10-25 14:00:00'], array_column($this->created, 'scheduled_at'));
    }

    public function test_creates_nothing_when_the_organizer_turned_them_off(): void
    {
        $this->enabled(false);
        $this->campaignRepository->shouldNotReceive('create');

        $this->assertSame([], $this->service->createForEvent($this->event('2026-11-07 17:00:00')));
    }

    public function test_never_creates_the_same_reminder_twice(): void
    {
        $this->enabled(true);
        $this->campaignRepository->shouldReceive('findFirstWhere')->with(['event_id' => 8, 'auto_key' => 'J-7'])
            ->andReturn((new SmsCampaignDomainObject)->setId(50)->setAutoKey('J-7'));
        $this->campaignRepository->shouldReceive('findFirstWhere')->with(['event_id' => 8, 'auto_key' => 'J-1'])
            ->andReturn((new SmsCampaignDomainObject)->setId(51)->setAutoKey('J-1'));
        $this->campaignRepository->shouldReceive('findFirstWhere')->with(['event_id' => 8, 'auto_key' => 'J'])->andReturnNull();

        $this->service->createForEvent($this->event('2026-11-07 17:00:00'));

        $this->assertSame(['J'], array_column($this->created, 'auto_key'));
    }

    public function test_reschedules_the_automatic_reminders_when_the_event_moves(): void
    {
        $this->enabled(false);
        $this->campaignRepository->shouldReceive('findWhere')->once()->withArgs(function (array $where) {
            return $where['event_id'] === 8
                && $where['status'] === 'SCHEDULED'
                && $where['auto_edited'] === false
                && in_array(['auto_key', 'not null', null], $where, true);
        })->andReturn(new Collection([
            (new SmsCampaignDomainObject)->setId(61)->setAutoKey('J-1')->setScheduledAt('2026-11-06 15:00:00'),
            (new SmsCampaignDomainObject)->setId(62)->setAutoKey('J')->setScheduledAt('2026-11-07 14:00:00'),
        ]));

        $this->campaignRepository->shouldReceive('updateWhere')->once()
            ->with(['scheduled_at' => '2026-11-13 15:00:00'], ['id' => 61, 'status' => 'SCHEDULED', 'auto_edited' => false])
            ->andReturn(1);
        $this->campaignRepository->shouldReceive('updateWhere')->once()
            ->with(['scheduled_at' => '2026-11-14 11:30:00'], ['id' => 62, 'status' => 'SCHEDULED', 'auto_edited' => false])
            ->andReturn(1);
        $this->recipientRepository->shouldReceive('updateWhere')->twice()->andReturn(0);

        $this->service->rescheduleForEvent($this->event('2026-11-14 14:30:00'));
    }

    public function test_a_reminder_moved_into_the_past_is_cancelled_and_a_missing_one_is_created(): void
    {
        $this->enabled(true);
        $this->campaignRepository->shouldReceive('findWhere')->once()->andReturn(new Collection([
            (new SmsCampaignDomainObject)->setId(60)->setAutoKey('J-7')->setScheduledAt('2026-10-31 15:00:00'),
        ]));
        $this->campaignRepository->shouldReceive('updateWhere')->once()->withArgs(function (array $attributes, array $where) {
            return $attributes['status'] === 'CANCELLED' && $where['id'] === 60;
        })->andReturn(1);
        $this->campaignRepository->shouldReceive('findFirstWhere')->with(['event_id' => 8, 'auto_key' => 'J-7'])
            ->andReturn((new SmsCampaignDomainObject)->setId(60)->setAutoKey('J-7'));
        $this->campaignRepository->shouldReceive('findFirstWhere')->andReturnNull();

        $this->service->rescheduleForEvent($this->event('2026-10-25 17:00:00'));

        $this->assertSame(['J-1', 'J'], array_column($this->created, 'auto_key'));
    }

    public function test_an_edited_reminder_is_left_alone(): void
    {
        $this->enabled(false);
        $this->campaignRepository->shouldReceive('findWhere')->once()
            ->withArgs(fn (array $where) => $where['auto_edited'] === false)
            ->andReturn(new Collection);
        $this->campaignRepository->shouldNotReceive('updateWhere');

        $this->service->rescheduleForEvent($this->event('2026-11-14 14:30:00'));
    }

    public function test_cancels_the_pending_automatic_reminders_of_an_event(): void
    {
        $this->campaignRepository->shouldReceive('updateWhere')->once()->withArgs(function (array $attributes, array $where) {
            return $attributes['status'] === 'CANCELLED'
                && $where['event_id'] === 8
                && $where['type'] === 'REMINDER'
                && $where['status'] === 'SCHEDULED'
                && in_array(['auto_key', 'not null', null], $where, true);
        })->andReturn(2);

        $this->assertSame(2, $this->service->cancelPendingForEvent(8));
    }

    public function test_cancels_every_pending_reminder_of_a_deleted_event(): void
    {
        $this->campaignRepository->shouldReceive('updateWhere')->once()->withArgs(function (array $attributes, array $where) {
            return $attributes['status'] === 'CANCELLED'
                && ! in_array(['auto_key', 'not null', null], $where, true);
        })->andReturn(3);

        $this->assertSame(3, $this->service->cancelPendingForEvent(8, automaticOnly: false));
    }

    public function test_a_failure_never_breaks_the_event_creation(): void
    {
        $this->organizerSettingsRepository->shouldReceive('findFirstWhere')->andThrow(new RuntimeException('Database unavailable'));
        $this->logger->shouldReceive('error')->once();

        $this->service->createForEventSafely($this->event('2026-11-07 17:00:00'));

        $this->assertSame([], $this->created);
    }

    private function enabled(bool $enabled): void
    {
        $this->organizerSettingsRepository->shouldReceive('findFirstWhere')->with(['organizer_id' => 9])
            ->andReturn((new OrganizerSettingDomainObject)->setOrganizerId(9)->setSmsDefaultRemindersEnabled($enabled));
    }

    private function noExistingReminder(): void
    {
        $this->campaignRepository->shouldReceive('findFirstWhere')->andReturnNull();
    }

    private function event(string $startUtc): EventDomainObject
    {
        return (new EventDomainObject)
            ->setId(8)
            ->setOrganizerId(9)
            ->setTitle('Konka Wawa')
            ->setStartDate($startUtc)
            ->setTimezone('Indian/Mayotte');
    }
}
