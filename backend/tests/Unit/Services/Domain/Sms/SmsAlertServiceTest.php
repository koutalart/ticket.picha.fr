<?php

namespace Tests\Unit\Services\Domain\Sms;

use HiEvents\DomainObjects\Generated\OrganizerSmsSettingDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerSmsSettingDomainObject;
use HiEvents\DomainObjects\SmsMessageDomainObject;
use HiEvents\DomainObjects\Status\SmsPhoneStatus;
use HiEvents\Jobs\Sms\SendSmsInboundAlertJob;
use HiEvents\Jobs\Sms\SendSmsPhoneOfflineAlertJob;
use HiEvents\Repository\Interfaces\OrganizerSmsSettingRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsConversationRepositoryInterface;
use HiEvents\Services\Domain\Sms\SmsAlertService;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Carbon;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class SmsAlertServiceTest extends TestCase
{
    private SmsConversationRepositoryInterface|MockInterface $conversationRepository;

    private OrganizerSmsSettingRepositoryInterface|MockInterface $settingsRepository;

    private Dispatcher|MockInterface $dispatcher;

    private SmsAlertService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-21 10:00:00');

        $this->conversationRepository = Mockery::mock(SmsConversationRepositoryInterface::class);
        $this->settingsRepository = Mockery::mock(OrganizerSmsSettingRepositoryInterface::class);
        $this->dispatcher = Mockery::mock(Dispatcher::class);

        $this->service = new SmsAlertService(
            $this->conversationRepository,
            $this->settingsRepository,
            $this->dispatcher,
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_inbound_alert_is_skipped_when_disabled(): void
    {
        $this->conversationRepository->shouldNotReceive('claimInboundNotification');
        $this->dispatcher->shouldNotReceive('dispatch');

        $this->service->notifyInbound($this->settings()->setNotifyOnInboundSms(false), $this->message());
    }

    public function test_inbound_alert_is_queued_once_per_window(): void
    {
        $this->conversationRepository->shouldReceive('claimInboundNotification')
            ->once()
            ->with(3, '2026-10-21 09:45:00', '2026-10-21 10:00:00')
            ->andReturnTrue();

        $this->dispatcher->shouldReceive('dispatch')
            ->once()
            ->withArgs(fn ($job) => $job instanceof SendSmsInboundAlertJob
                && $job->conversationId === 3
                && $job->delay instanceof Carbon
                && $job->delay->toDateTimeString() === '2026-10-21 10:01:00');

        $this->service->notifyInbound($this->settings(), $this->message());
    }

    public function test_inbound_alert_is_not_queued_when_recently_notified(): void
    {
        $this->conversationRepository->shouldReceive('claimInboundNotification')->once()->andReturnFalse();
        $this->dispatcher->shouldNotReceive('dispatch');

        $this->service->notifyInbound($this->settings(), $this->message());
    }

    public function test_phone_going_offline_is_stored_and_alerted(): void
    {
        $settings = $this->settings()
            ->setPhoneStatus(SmsPhoneStatus::ONLINE->name)
            ->setPhoneStatusChangedAt('2026-10-21 08:00:00');

        $this->settingsRepository->shouldReceive('updateFromArray')
            ->once()
            ->with(1, [
                OrganizerSmsSettingDomainObjectAbstract::PHONE_STATUS => 'OFFLINE',
                OrganizerSmsSettingDomainObjectAbstract::PHONE_STATUS_CHANGED_AT => '2026-10-21 09:30:00',
            ])
            ->andReturn($settings);

        $this->dispatcher->shouldReceive('dispatch')
            ->once()
            ->withArgs(fn ($job) => $job instanceof SendSmsPhoneOfflineAlertJob && $job->settingsId === 1);

        $this->service->recordPhoneStatus($settings, SmsPhoneStatus::OFFLINE, '2026-10-21T13:30:00+04:00');
    }

    public function test_repeated_offline_event_is_not_alerted_twice(): void
    {
        $settings = $this->settings()
            ->setPhoneStatus(SmsPhoneStatus::OFFLINE->name)
            ->setPhoneStatusChangedAt('2026-10-21 08:00:00');

        $this->settingsRepository->shouldNotReceive('updateFromArray');
        $this->dispatcher->shouldNotReceive('dispatch');

        $this->service->recordPhoneStatus($settings, SmsPhoneStatus::OFFLINE, '2026-10-21T09:00:00Z');
    }

    public function test_out_of_order_event_is_ignored(): void
    {
        $settings = $this->settings()
            ->setPhoneStatus(SmsPhoneStatus::ONLINE->name)
            ->setPhoneStatusChangedAt('2026-10-21 09:00:00');

        $this->settingsRepository->shouldNotReceive('updateFromArray');
        $this->dispatcher->shouldNotReceive('dispatch');

        $this->service->recordPhoneStatus($settings, SmsPhoneStatus::OFFLINE, '2026-10-21T08:00:00Z');
    }

    public function test_phone_back_online_is_stored_without_alert(): void
    {
        $settings = $this->settings()
            ->setPhoneStatus(SmsPhoneStatus::OFFLINE->name)
            ->setPhoneStatusChangedAt('2026-10-21 08:00:00');

        $this->settingsRepository->shouldReceive('updateFromArray')
            ->once()
            ->with(1, [
                OrganizerSmsSettingDomainObjectAbstract::PHONE_STATUS => 'ONLINE',
                OrganizerSmsSettingDomainObjectAbstract::PHONE_STATUS_CHANGED_AT => '2026-10-21 10:00:00',
            ])
            ->andReturn($settings);
        $this->dispatcher->shouldNotReceive('dispatch');

        $this->service->markPhoneActive($settings);
    }

    public function test_phone_activity_does_nothing_when_not_offline(): void
    {
        $this->settingsRepository->shouldNotReceive('updateFromArray');

        $settings = $this->settings();

        $this->assertSame($settings, $this->service->markPhoneActive($settings));
    }

    private function settings(): OrganizerSmsSettingDomainObject
    {
        return (new OrganizerSmsSettingDomainObject)
            ->setId(1)
            ->setOrganizerId(4)
            ->setNotifyOnInboundSms(true);
    }

    private function message(): SmsMessageDomainObject
    {
        return (new SmsMessageDomainObject)->setId(9)->setSmsConversationId(3);
    }
}
