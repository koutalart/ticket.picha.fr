<?php

namespace Tests\Unit\Services\Domain\Sms\Reminder;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\SmsCampaignDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Exceptions\SmsReminderNotCancellableException;
use HiEvents\Exceptions\SmsReminderValidationException;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsCampaignRecipientRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsCampaignRepositoryInterface;
use HiEvents\Services\Domain\Sms\DTO\SmsReminderAudienceDTO;
use HiEvents\Services\Domain\Sms\DTO\SmsReminderRecipientDTO;
use HiEvents\Services\Domain\Sms\DTO\SmsReminderRequestDTO;
use HiEvents\Services\Domain\Sms\Reminder\SmsReminderAudienceService;
use HiEvents\Services\Domain\Sms\Reminder\SmsReminderMessageRenderer;
use HiEvents\Services\Domain\Sms\Reminder\SmsReminderPersonalLinkProviderInterface;
use HiEvents\Services\Domain\Sms\Reminder\SmsReminderSchedulingService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class SmsReminderSchedulingServiceTest extends TestCase
{
    private OrganizerRepositoryInterface|MockInterface $organizerRepository;

    private EventRepositoryInterface|MockInterface $eventRepository;

    private SmsCampaignRepositoryInterface|MockInterface $campaignRepository;

    private SmsCampaignRecipientRepositoryInterface|MockInterface $recipientRepository;

    private SmsReminderAudienceService|MockInterface $audienceService;

    private SmsReminderPersonalLinkProviderInterface|MockInterface $linkProvider;

    private SmsReminderSchedulingService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-22 06:00:00');

        $this->organizerRepository = Mockery::mock(OrganizerRepositoryInterface::class);
        $this->eventRepository = Mockery::mock(EventRepositoryInterface::class);
        $this->campaignRepository = Mockery::mock(SmsCampaignRepositoryInterface::class);
        $this->recipientRepository = Mockery::mock(SmsCampaignRecipientRepositoryInterface::class);
        $this->audienceService = Mockery::mock(SmsReminderAudienceService::class);
        $this->linkProvider = Mockery::mock(SmsReminderPersonalLinkProviderInterface::class);
        $databaseManager = Mockery::mock(DatabaseManager::class);
        $databaseManager->shouldReceive('transaction')->andReturnUsing(fn (callable $callback) => $callback());

        $this->eventRepository->shouldReceive('findFirstWhere')
            ->with(['id' => 8, 'organizer_id' => 9])
            ->andReturn($this->event());
        $this->organizerRepository->shouldReceive('findFirstWhere')
            ->andReturn((new OrganizerDomainObject)->setId(9)->setAccountId(3));

        $this->service = new SmsReminderSchedulingService(
            $this->organizerRepository,
            $this->eventRepository,
            $this->campaignRepository,
            $this->recipientRepository,
            $this->audienceService,
            new SmsReminderMessageRenderer,
            $this->linkProvider,
            $databaseManager,
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_preview_counts_recipients_and_sms(): void
    {
        $this->audienceService->shouldReceive('build')->with(9, 8)->andReturn($this->audience());
        $this->linkProvider->shouldReceive('findForAttendee')->with(11)->once()->andReturnNull();

        $preview = $this->service->preview(new SmsReminderRequestDTO(
            organizerId: 9,
            eventId: 8,
            message: 'Bonjour {prenom}, {evenement} commence à {heure}. {lien}',
            sendAt: '2026-10-24 10:00',
        ));

        $this->assertSame(2, $preview->recipients);
        $this->assertSame(2, $preview->smsCount);
        $this->assertSame('Bonjour Awa, Konka Wawa commence à 20h00.', $preview->sampleMessage);
        $this->assertSame(1, $preview->optedOut);
        $this->assertFalse($preview->personalLinkAvailable);
        $this->assertSame('Indian/Mayotte', $preview->eventTimezone);
        $this->assertSame('2026-10-24 07:00:00', $preview->scheduledAt);
    }

    public function test_preview_counts_long_messages_as_several_sms(): void
    {
        $this->audienceService->shouldReceive('build')->andReturn($this->audience());
        $this->linkProvider->shouldReceive('findForAttendee')->andReturn('https://ticket.picha.fr/c/'.str_repeat('x', 40));

        $preview = $this->service->preview(new SmsReminderRequestDTO(9, 8, str_repeat('a', 130).' {lien}'));

        $this->assertSame(4, $preview->smsCount);
        $this->assertTrue($preview->personalLinkAvailable);
    }

    public function test_schedules_a_reminder_with_its_recipients(): void
    {
        $this->audienceService->shouldReceive('build')->with(9, 8)->andReturn($this->audience());

        $this->campaignRepository->shouldReceive('create')->once()->withArgs(function (array $attributes) {
            return $attributes['type'] === 'REMINDER'
                && $attributes['status'] === 'SCHEDULED'
                && $attributes['scheduled_at'] === '2026-10-24 07:00:00'
                && $attributes['account_id'] === 3
                && $attributes['event_id'] === 8
                && $attributes['created_by_user_id'] === 5
                && $attributes['message'] === 'A ce soir {prenom}'
                && $attributes['name'] === 'Rappel Konka Wawa, 24 octobre 10:00';
        })->andReturn((new SmsCampaignDomainObject)->setId(70)->setScheduledAt('2026-10-24 07:00:00'));

        $this->recipientRepository->shouldReceive('insert')->once()->withArgs(function (array $rows) {
            return count($rows) === 2
                && $rows[0]['sms_campaign_id'] === 70
                && $rows[0]['phone'] === '+262639123456'
                && $rows[0]['attendee_id'] === 11
                && $rows[0]['status'] === 'PENDING'
                && $rows[0]['scheduled_at'] === '2026-10-24 07:00:00';
        })->andReturnTrue();

        app()->setLocale('fr');
        $campaign = $this->service->schedule(new SmsReminderRequestDTO(
            organizerId: 9,
            eventId: 8,
            message: '  A ce soir {prenom} ',
            sendAt: '2026-10-24 10:00',
            userId: 5,
        ));

        $this->assertSame(70, $campaign->getId());
    }

    public function test_refuses_a_date_in_the_past(): void
    {
        $this->campaignRepository->shouldNotReceive('create');

        $this->expectException(SmsReminderValidationException::class);
        $this->service->schedule(new SmsReminderRequestDTO(9, 8, 'Rappel', '2026-10-22 08:59'));
    }

    public function test_refuses_an_invalid_date(): void
    {
        $this->expectException(SmsReminderValidationException::class);
        $this->service->schedule(new SmsReminderRequestDTO(9, 8, 'Rappel', '24/10/2026'));
    }

    public function test_schedules_a_reminder_before_any_reachable_attendee(): void
    {
        $this->audienceService->shouldReceive('build')->andReturn(new SmsReminderAudienceDTO([], 3, 3, 0, 0));
        $this->campaignRepository->shouldReceive('create')->once()
            ->andReturn((new SmsCampaignDomainObject)->setId(71)->setScheduledAt('2026-10-24 07:00:00'));
        $this->recipientRepository->shouldReceive('insert')->zeroOrMoreTimes()->andReturnTrue();

        $campaign = $this->service->schedule(new SmsReminderRequestDTO(9, 8, 'Rappel', '2026-10-24 10:00'));

        $this->assertSame(71, $campaign->getId());
    }

    public function test_refuses_an_event_of_another_organizer(): void
    {
        $this->eventRepository->shouldReceive('findFirstWhere')->with(['id' => 99, 'organizer_id' => 9])->andReturnNull();

        $this->expectException(ResourceNotFoundException::class);
        $this->service->preview(new SmsReminderRequestDTO(9, 99, 'Rappel'));
    }

    public function test_cancels_a_scheduled_reminder(): void
    {
        $this->campaignRepository->shouldReceive('findFirstWhere')->andReturn((new SmsCampaignDomainObject)->setId(70));
        $this->campaignRepository->shouldReceive('updateWhere')->once()->withArgs(function (array $attributes, array $where) {
            return $attributes['status'] === 'CANCELLED'
                && $where === ['id' => 70, 'organizer_id' => 9, 'type' => 'REMINDER', 'status' => 'SCHEDULED'];
        })->andReturn(1);

        $this->service->cancel(9, 70);
    }

    public function test_cannot_cancel_a_reminder_already_being_sent(): void
    {
        $this->campaignRepository->shouldReceive('findFirstWhere')->andReturn((new SmsCampaignDomainObject)->setId(70));
        $this->campaignRepository->shouldReceive('updateWhere')->andReturn(0);

        $this->expectException(SmsReminderNotCancellableException::class);
        $this->service->cancel(9, 70);
    }

    public function test_cannot_cancel_an_unknown_reminder(): void
    {
        $this->campaignRepository->shouldReceive('findFirstWhere')->andReturnNull();
        $this->campaignRepository->shouldNotReceive('updateWhere');

        $this->expectException(ResourceNotFoundException::class);
        $this->service->cancel(9, 70);
    }

    private function audience(): SmsReminderAudienceDTO
    {
        return new SmsReminderAudienceDTO(
            recipients: [
                new SmsReminderRecipientDTO('+262639123456', 11, 'Awa', 'Awa Saïd'),
                new SmsReminderRecipientDTO('+262639000002', 12, 'Fatima', 'Fatima Ali'),
            ],
            activeAttendees: 4,
            withoutValidPhone: 1,
            optedOut: 1,
            sharedPhone: 0,
        );
    }

    private function event(): EventDomainObject
    {
        return (new EventDomainObject)
            ->setId(8)
            ->setTitle('Konka Wawa')
            ->setStartDate('2026-10-24 17:00:00')
            ->setTimezone('Indian/Mayotte');
    }
}
