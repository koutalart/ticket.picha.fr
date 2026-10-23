<?php

namespace Tests\Unit\Services\Domain\Sms\Reminder;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\Enums\SmsMessageAuthorType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\SmsCampaignDomainObject;
use HiEvents\DomainObjects\SmsCampaignRecipientDomainObject;
use HiEvents\DomainObjects\SmsMessageDomainObject;
use HiEvents\Exceptions\SmsGatewayException;
use HiEvents\Exceptions\SmsGatewayNotConfiguredException;
use HiEvents\Exceptions\SmsRecipientOptedOutException;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsCampaignRecipientRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsCampaignRepositoryInterface;
use HiEvents\Services\Domain\Sms\DTO\SendSmsDTO;
use HiEvents\Services\Domain\Sms\DTO\SmsReminderAudienceDTO;
use HiEvents\Services\Domain\Sms\DTO\SmsReminderRecipientDTO;
use HiEvents\Services\Domain\Sms\Reminder\SmsReminderAudienceService;
use HiEvents\Services\Domain\Sms\Reminder\SmsReminderDispatchService;
use HiEvents\Services\Domain\Sms\Reminder\SmsReminderMessageRenderer;
use HiEvents\Services\Domain\Sms\Reminder\SmsReminderPersonalLinkProviderInterface;
use HiEvents\Services\Domain\Sms\Reminder\SmsReminderSchedulingService;
use HiEvents\Services\Domain\Sms\SmsSendService;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Mockery;
use Mockery\MockInterface;
use Psr\Log\NullLogger;
use Tests\TestCase;

class SmsReminderDispatchServiceTest extends TestCase
{
    private SmsCampaignRepositoryInterface|MockInterface $campaignRepository;

    private SmsCampaignRecipientRepositoryInterface|MockInterface $recipientRepository;

    private EventRepositoryInterface|MockInterface $eventRepository;

    private AttendeeRepositoryInterface|MockInterface $attendeeRepository;

    private SmsReminderAudienceService|MockInterface $audienceService;

    private SmsReminderPersonalLinkProviderInterface|MockInterface $linkProvider;

    private SmsSendService|MockInterface $sendService;

    private SmsReminderDispatchService $service;

    /** @var array<int, array{0: array, 1: array}> */
    private array $campaignUpdates = [];

    /** @var array<int, string> recipient id => status */
    private array $recipientStatuses = [];

    /** @var array<int, array> */
    private array $recipientUpdates = [];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-24 07:00:30');

        $this->campaignRepository = Mockery::mock(SmsCampaignRepositoryInterface::class);
        $this->recipientRepository = Mockery::mock(SmsCampaignRecipientRepositoryInterface::class);
        $this->eventRepository = Mockery::mock(EventRepositoryInterface::class);
        $this->attendeeRepository = Mockery::mock(AttendeeRepositoryInterface::class);
        $this->audienceService = Mockery::mock(SmsReminderAudienceService::class);
        $this->linkProvider = Mockery::mock(SmsReminderPersonalLinkProviderInterface::class);
        $this->sendService = Mockery::mock(SmsSendService::class);

        $this->linkProvider->shouldReceive('findForAttendee')->andReturnNull()->byDefault();
        $this->eventRepository->shouldReceive('findFirstWhere')->with(['id' => 8])->andReturn(
            (new EventDomainObject)->setId(8)->setTitle('Konka Wawa')->setStartDate('2026-10-24 17:00:00')->setTimezone('Indian/Mayotte')
        )->byDefault();
        $this->attendeeRepository->shouldReceive('findWhereIn')->andReturn(collect([
            (new AttendeeDomainObject)->setId(11)->setFirstName('Awa'),
            (new AttendeeDomainObject)->setId(12)->setFirstName('Fatima'),
        ]))->byDefault();

        $this->campaignRepository->shouldReceive('updateWhere')->andReturnUsing(function (array $attributes, array $where) {
            $this->campaignUpdates[] = [$attributes, $where];

            return 1;
        })->byDefault();

        $this->recipientRepository->shouldReceive('updateWhere')->andReturnUsing(function (array $attributes, array $where) {
            if (isset($where['id'])) {
                if (($this->recipientStatuses[$where['id']] ?? null) !== $where['status']) {
                    return 0;
                }
                $this->recipientStatuses[$where['id']] = $attributes['status'];

                return 1;
            }

            foreach ($this->recipientStatuses as $id => $status) {
                if ($status === $where['status']) {
                    $this->recipientStatuses[$id] = $attributes['status'];
                }
            }

            return 1;
        })->byDefault();

        $this->recipientRepository->shouldReceive('updateFromArray')->andReturnUsing(function (int $id, array $attributes) {
            $this->recipientUpdates[$id] = $attributes;
            if (isset($attributes['status'])) {
                $this->recipientStatuses[$id] = $attributes['status'];
            }

            return new SmsCampaignRecipientDomainObject;
        })->byDefault();

        $this->recipientRepository->shouldReceive('countWhere')->andReturnUsing(function (array $where) {
            return count(array_filter($this->recipientStatuses, function (string $status) use ($where) {
                if (isset($where['status'])) {
                    return $status === $where['status'];
                }
                if (isset($where[0])) {
                    return in_array($status, $where[0][2], true);
                }

                return true;
            }));
        })->byDefault();

        $config = new ConfigRepository(['services' => ['httpsms' => ['reminder_batch_size' => 10, 'reminder_pause_ms' => 0]]]);

        $this->service = new SmsReminderDispatchService(
            $this->campaignRepository,
            $this->recipientRepository,
            $this->eventRepository,
            $this->attendeeRepository,
            $this->audienceService,
            Mockery::mock(SmsReminderSchedulingService::class)->makePartial(),
            new SmsReminderMessageRenderer,
            $this->linkProvider,
            $this->sendService,
            $config,
            new NullLogger,
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_sends_a_due_reminder_once_to_each_recipient_and_completes_it(): void
    {
        $campaign = $this->campaign();
        $this->expectCampaigns(due: [$campaign], processing: [$campaign]);
        $this->expectAudience(['+262639123456', '+262639000002']);
        $this->givenRecipients([
            $this->recipient(101, '+262639123456', 11),
            $this->recipient(102, '+262639000002', 12),
        ]);

        $sent = [];
        $this->sendService->shouldReceive('send')->twice()->andReturnUsing(function (SendSmsDTO $sms) use (&$sent) {
            $sent[] = $sms;

            return (new SmsMessageDomainObject)->setProviderMessageId('msg-'.count($sent));
        });

        $result = $this->service->dispatchDue();

        $this->assertSame(1, $result->startedReminders);
        $this->assertSame(2, $result->sent);
        $this->assertSame(0, $result->failed);
        $this->assertSame(1, $result->completedReminders);

        $this->assertSame('+262639123456', $sent[0]->phone);
        $this->assertSame('Bonjour Awa, Konka Wawa à 20h00.', $sent[0]->content);
        $this->assertSame(SmsMessageAuthorType::CAMPAIGN, $sent[0]->authorType);
        $this->assertSame(5, $sent[0]->sentByUserId);
        $this->assertSame('Bonjour Fatima, Konka Wawa à 20h00.', $sent[1]->content);

        $this->assertSame(['provider_message_id' => 'msg-1'], $this->recipientUpdates[101]);
        $this->assertSame(['SCHEDULED', 'SCHEDULED'], array_values($this->recipientStatuses));

        [$claim, $claimWhere] = $this->campaignUpdates[0];
        $this->assertSame('PROCESSING', $claim['status']);
        $this->assertSame(['id' => 70, 'status' => 'SCHEDULED'], $claimWhere);

        [$done, $doneWhere] = end($this->campaignUpdates);
        $this->assertSame('SENT', $done['status']);
        $this->assertSame(['id' => 70, 'status' => 'PROCESSING'], $doneWhere);
    }

    public function test_never_sends_to_a_recipient_already_claimed(): void
    {
        $campaign = $this->campaign();
        $this->expectCampaigns(due: [], processing: [$campaign]);
        $this->givenRecipients([$this->recipient(101, '+262639123456', 11)]);
        $this->recipientStatuses[101] = 'SCHEDULED';

        $this->sendService->shouldNotReceive('send');

        $result = $this->service->dispatchDue();

        $this->assertSame(0, $result->sent);
    }

    public function test_a_reminder_claimed_by_another_run_is_not_started_twice(): void
    {
        $campaign = $this->campaign();
        $this->expectCampaigns(due: [$campaign], processing: []);
        $this->campaignRepository->shouldReceive('updateWhere')->andReturn(0);
        $this->audienceService->shouldNotReceive('build');

        $result = $this->service->dispatchDue();

        $this->assertSame(0, $result->startedReminders);
    }

    public function test_refresh_adds_new_attendees_and_drops_those_no_longer_reachable(): void
    {
        $campaign = $this->campaign();
        $this->expectCampaigns(due: [$campaign], processing: []);
        $this->expectAudience(['+262639123456', '+262639000003']);

        $this->recipientRepository->shouldReceive('findWhere')->once()->andReturn(collect([
            $this->recipient(101, '+262639123456', 11),
            $this->recipient(102, '+262639000002', 12),
        ]));
        $this->recipientRepository->shouldReceive('deleteWhere')->once()->with([
            'sms_campaign_id' => 70,
            'status' => 'PENDING',
            ['id', 'in', [102]],
        ])->andReturn(1);

        $scheduling = Mockery::mock(SmsReminderSchedulingService::class);
        $scheduling->shouldReceive('recipientAttributes')->once()->andReturnUsing(
            fn (SmsCampaignDomainObject $campaign, SmsReminderRecipientDTO $recipient) => ['phone' => $recipient->phone]
        );
        $this->recipientRepository->shouldReceive('insert')->once()->with([['phone' => '+262639000003']])->andReturnTrue();

        $this->rebuildServiceWith($scheduling);

        $this->assertSame(1, $this->service->dispatchDue()->startedReminders);
    }

    public function test_failed_recipients_are_recorded_and_the_batch_continues(): void
    {
        $campaign = $this->campaign();
        $this->expectCampaigns(due: [], processing: [$campaign]);
        $this->givenRecipients([
            $this->recipient(101, '+262639123456', 11),
            $this->recipient(102, '+262639000002', 12),
        ]);

        $this->sendService->shouldReceive('send')->twice()->andReturnUsing(function (SendSmsDTO $sms) {
            if ($sms->phone === '+262639123456') {
                throw new SmsRecipientOptedOutException('STOP');
            }

            throw new SmsGatewayException('Phone offline');
        });

        $result = $this->service->dispatchDue();

        $this->assertSame(2, $result->failed);
        $this->assertSame('STOP', $this->recipientUpdates[101]['failure_reason']);
        $this->assertSame(['FAILED', 'FAILED'], array_values($this->recipientStatuses));

        [$done] = end($this->campaignUpdates);
        $this->assertSame('FAILED', $done['status']);
    }

    public function test_unconfigured_gateway_fails_the_whole_reminder(): void
    {
        $campaign = $this->campaign();
        $this->expectCampaigns(due: [], processing: [$campaign]);
        $this->givenRecipients([
            $this->recipient(101, '+262639123456', 11),
            $this->recipient(102, '+262639000002', 12),
        ]);

        $this->sendService->shouldReceive('send')->once()->andThrow(new SmsGatewayNotConfiguredException('Not configured'));

        $this->service->dispatchDue();

        $this->assertSame(['FAILED', 'FAILED'], array_values($this->recipientStatuses));
        $failure = collect($this->campaignUpdates)->first(fn (array $update) => ($update[0]['status'] ?? null) === 'FAILED');
        $this->assertSame('Not configured', $failure[0]['failure_reason']);
    }

    public function test_respects_the_batch_size(): void
    {
        $campaign = $this->campaign();
        $this->expectCampaigns(due: [], processing: [$campaign]);
        $recipients = [];
        for ($id = 1; $id <= 12; $id++) {
            $recipients[] = $this->recipient($id, '+2626390000'.str_pad((string) $id, 2, '0', STR_PAD_LEFT), null);
        }
        $this->givenRecipients($recipients);

        $this->sendService->shouldReceive('send')->times(10)->andReturn(new SmsMessageDomainObject);

        $result = $this->service->dispatchDue();

        $this->assertSame(10, $result->sent);
        $this->assertSame(0, $result->completedReminders);
        $this->assertSame(2, count(array_filter($this->recipientStatuses, fn (string $status) => $status === 'PENDING')));
    }

    private function rebuildServiceWith(SmsReminderSchedulingService $scheduling): void
    {
        $this->service = new SmsReminderDispatchService(
            $this->campaignRepository,
            $this->recipientRepository,
            $this->eventRepository,
            $this->attendeeRepository,
            $this->audienceService,
            $scheduling,
            new SmsReminderMessageRenderer,
            $this->linkProvider,
            $this->sendService,
            new ConfigRepository(['services' => ['httpsms' => ['reminder_batch_size' => 10, 'reminder_pause_ms' => 0]]]),
            new NullLogger,
        );
    }

    private function campaign(): SmsCampaignDomainObject
    {
        return (new SmsCampaignDomainObject)
            ->setId(70)
            ->setOrganizerId(9)
            ->setEventId(8)
            ->setMessage('Bonjour {prenom}, {evenement} à {heure}.')
            ->setScheduledAt('2026-10-24 07:00:00')
            ->setCreatedByUserId(5);
    }

    private function recipient(int $id, string $phone, ?int $attendeeId): SmsCampaignRecipientDomainObject
    {
        $this->recipientStatuses[$id] = 'PENDING';

        return (new SmsCampaignRecipientDomainObject)
            ->setId($id)
            ->setSmsCampaignId(70)
            ->setPhone($phone)
            ->setAttendeeId($attendeeId)
            ->setStatus('PENDING');
    }

    private function expectCampaigns(array $due, array $processing): void
    {
        $this->campaignRepository->shouldReceive('findWhere')
            ->withArgs(fn (array $where) => ($where['status'] ?? null) === 'SCHEDULED')
            ->andReturn(collect($due));
        $this->campaignRepository->shouldReceive('findWhere')
            ->withArgs(fn (array $where) => ($where['status'] ?? null) === 'PROCESSING')
            ->andReturn(collect($processing));
    }

    private function expectAudience(array $phones): void
    {
        $this->audienceService->shouldReceive('build')->with(9, 8)->andReturn(new SmsReminderAudienceDTO(
            recipients: array_map(fn (string $phone) => new SmsReminderRecipientDTO($phone, 1, null, null), $phones),
            activeAttendees: count($phones),
            withoutValidPhone: 0,
            optedOut: 0,
            sharedPhone: 0,
        ));
    }

    /**
     * @param  SmsCampaignRecipientDomainObject[]  $recipients
     */
    private function givenRecipients(array $recipients): void
    {
        $this->recipientRepository->shouldReceive('findWhere')->andReturnUsing(function (array $where) use ($recipients): Collection {
            return collect($recipients)->filter(
                fn (SmsCampaignRecipientDomainObject $recipient) => ! isset($where['status']) || $this->recipientStatuses[$recipient->getId()] === $where['status']
            )->values();
        });
    }
}
