<?php

namespace Tests\Unit\Jobs\Sms;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\OrganizerSmsSettingDomainObject;
use HiEvents\DomainObjects\SmsConversationDomainObject;
use HiEvents\DomainObjects\SmsMessageDomainObject;
use HiEvents\DomainObjects\Status\SmsPhoneStatus;
use HiEvents\Jobs\Sms\SendSmsInboundAlertJob;
use HiEvents\Jobs\Sms\SendSmsPhoneOfflineAlertJob;
use HiEvents\Mail\Sms\SmsInboundAlertMail;
use HiEvents\Mail\Sms\SmsPhoneOfflineAlertMail;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerSmsSettingRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsConversationRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsMessageRepositoryInterface;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Mail\PendingMail;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class SendSmsAlertJobsTest extends TestCase
{
    private SmsConversationRepositoryInterface|MockInterface $conversationRepository;

    private SmsMessageRepositoryInterface|MockInterface $messageRepository;

    private OrganizerSmsSettingRepositoryInterface|MockInterface $settingsRepository;

    private OrganizerRepositoryInterface|MockInterface $organizerRepository;

    private Mailer|MockInterface $mailer;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.customer_locale' => 'fr']);

        $this->conversationRepository = Mockery::mock(SmsConversationRepositoryInterface::class);
        $this->messageRepository = Mockery::mock(SmsMessageRepositoryInterface::class);
        $this->settingsRepository = Mockery::mock(OrganizerSmsSettingRepositoryInterface::class);
        $this->organizerRepository = Mockery::mock(OrganizerRepositoryInterface::class);
        $this->mailer = Mockery::mock(Mailer::class);
    }

    public function test_inbound_alert_is_sent_in_french_to_the_organizer(): void
    {
        $this->conversationRepository->shouldReceive('findFirstWhere')->with(['id' => 3])->andReturn($this->conversation(unread: 2));
        $this->settingsRepository->shouldReceive('findFirstWhere')->andReturn($this->settings());
        $this->organizerRepository->shouldReceive('findFirstWhere')->with(['id' => 4])->andReturn($this->organizer());
        $this->messageRepository->shouldReceive('findLatestInbound')
            ->once()
            ->with(3, 2)
            ->andReturn(collect([(new SmsMessageDomainObject)->setContent('Bonjour')]));

        $this->expectMailTo(SmsInboundAlertMail::class);

        $this->runInboundJob();
    }

    public function test_inbound_alert_is_skipped_when_conversation_was_read(): void
    {
        $this->conversationRepository->shouldReceive('findFirstWhere')->andReturn($this->conversation(unread: 0));
        $this->mailer->shouldNotReceive('to');

        $this->runInboundJob();
    }

    public function test_inbound_alert_is_skipped_when_alerts_were_turned_off(): void
    {
        $this->conversationRepository->shouldReceive('findFirstWhere')->andReturn($this->conversation(unread: 1));
        $this->settingsRepository->shouldReceive('findFirstWhere')->andReturn($this->settings()->setNotifyOnInboundSms(false));
        $this->mailer->shouldNotReceive('to');

        $this->runInboundJob();
    }

    public function test_offline_alert_is_sent_while_phone_is_offline(): void
    {
        $this->settingsRepository->shouldReceive('findFirstWhere')->with(['id' => 1])
            ->andReturn($this->settings()->setPhoneStatus(SmsPhoneStatus::OFFLINE->name));
        $this->organizerRepository->shouldReceive('findFirstWhere')->with(['id' => 4])->andReturn($this->organizer());

        $this->expectMailTo(SmsPhoneOfflineAlertMail::class);

        $this->runOfflineJob();
    }

    public function test_offline_alert_is_skipped_when_phone_is_back_online(): void
    {
        $this->settingsRepository->shouldReceive('findFirstWhere')
            ->andReturn($this->settings()->setPhoneStatus(SmsPhoneStatus::ONLINE->name));
        $this->mailer->shouldNotReceive('to');

        $this->runOfflineJob();
    }

    private function expectMailTo(string $mailClass): void
    {
        $pending = Mockery::mock(PendingMail::class);
        $this->mailer->shouldReceive('to')->once()->with('orga@example.test', 'Innocent Event')->andReturn($pending);
        $pending->shouldReceive('locale')->once()->with('fr')->andReturnSelf();
        $pending->shouldReceive('send')->once()->with(Mockery::type($mailClass));
    }

    private function runInboundJob(): void
    {
        (new SendSmsInboundAlertJob(3))->handle(
            $this->conversationRepository,
            $this->messageRepository,
            $this->settingsRepository,
            $this->organizerRepository,
            $this->mailer,
        );
    }

    private function runOfflineJob(): void
    {
        (new SendSmsPhoneOfflineAlertJob(1))->handle(
            $this->settingsRepository,
            $this->organizerRepository,
            $this->mailer,
        );
    }

    private function conversation(int $unread): SmsConversationDomainObject
    {
        return (new SmsConversationDomainObject)
            ->setId(3)
            ->setOrganizerId(4)
            ->setPhone('+262639123456')
            ->setUnreadCount($unread);
    }

    private function settings(): OrganizerSmsSettingDomainObject
    {
        return (new OrganizerSmsSettingDomainObject)
            ->setId(1)
            ->setOrganizerId(4)
            ->setNotifyOnInboundSms(true);
    }

    private function organizer(): OrganizerDomainObject
    {
        return (new OrganizerDomainObject)
            ->setId(4)
            ->setName('Innocent Event')
            ->setEmail('orga@example.test')
            ->setTimezone('Indian/Mayotte');
    }
}
