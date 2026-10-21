<?php

namespace Tests\Unit\Services\Domain\Sms;

use HiEvents\DomainObjects\OrganizerSmsSettingDomainObject;
use HiEvents\DomainObjects\SmsMessageDomainObject;
use HiEvents\DomainObjects\Status\SmsHistoryImportStatus;
use HiEvents\DomainObjects\Status\SmsMessageStatus;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Exceptions\SmsGatewayException;
use HiEvents\Exceptions\SmsGatewayNotConfiguredException;
use HiEvents\Repository\Interfaces\OrganizerSmsSettingRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsMessageRepositoryInterface;
use HiEvents\Services\Domain\Sms\DTO\InboundSmsDTO;
use HiEvents\Services\Domain\Sms\DTO\OutboundSmsEventDTO;
use HiEvents\Services\Domain\Sms\SmsHistoryImportService;
use HiEvents\Services\Domain\Sms\SmsInboundService;
use HiEvents\Services\Infrastructure\HttpSms\HttpSmsClient;
use Mockery;
use Mockery\MockInterface;
use Psr\Log\NullLogger;
use Tests\TestCase;

class SmsHistoryImportServiceTest extends TestCase
{
    private HttpSmsClient|MockInterface $client;

    private SmsInboundService|MockInterface $inboundService;

    private SmsMessageRepositoryInterface|MockInterface $messageRepository;

    private OrganizerSmsSettingRepositoryInterface|MockInterface $settingsRepository;

    private SmsHistoryImportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Mockery::mock(HttpSmsClient::class);
        $this->inboundService = Mockery::mock(SmsInboundService::class);
        $this->messageRepository = Mockery::mock(SmsMessageRepositoryInterface::class);
        $this->settingsRepository = Mockery::mock(OrganizerSmsSettingRepositoryInterface::class);

        $this->service = new SmsHistoryImportService(
            $this->client,
            $this->inboundService,
            $this->messageRepository,
            $this->settingsRepository,
            new NullLogger,
        );
    }

    public function test_mark_started_requires_api_key_and_number(): void
    {
        $this->expectException(SmsGatewayNotConfiguredException::class);
        $this->settingsRepository->shouldNotReceive('updateFromArray');

        $this->service->markStarted((new OrganizerSmsSettingDomainObject)->setId(1)->setApiKey('uk_key'));
    }

    public function test_mark_started_refuses_a_running_import(): void
    {
        $this->expectException(ResourceConflictException::class);

        $this->service->markStarted($this->settings()->setHistoryImportStartedAt(now()->subMinutes(5)->toDateTimeString()));
    }

    public function test_mark_started_restarts_a_stale_import(): void
    {
        $settings = $this->settings()->setHistoryImportStartedAt(now()->subHours(3)->toDateTimeString());

        $this->settingsRepository->shouldReceive('updateFromArray')
            ->once()
            ->withArgs(fn (int $id, array $attributes) => $id === 1
                && $attributes['history_import_status'] === SmsHistoryImportStatus::RUNNING->name
                && $attributes['history_import_count'] === 0
                && $attributes['history_import_error'] === null)
            ->andReturn($settings);

        $this->service->markStarted($settings);
    }

    public function test_import_page_records_new_messages_oldest_first_without_unread(): void
    {
        $this->settingsRepository->shouldReceive('findFirst')->with(1)->andReturn($this->settings());
        $this->client->shouldReceive('listMessageThreads')
            ->with('uk_key', '+262639000000', 0, 20)
            ->andReturn([['contact' => '+262639123456']]);
        $this->client->shouldReceive('listMessages')
            ->with('uk_key', '+262639000000', '+262639123456', 0, 20)
            ->andReturn([
                $this->message('in-1', 'mobile-originated', 'received', 'Stop', '2026-10-21T12:00:00+03:00'),
                $this->message('out-1', 'mobile-terminated', 'delivered', 'Halloween le 31/10', '2026-10-21T11:00:00+03:00'),
            ]);

        $this->messageRepository->shouldReceive('findFirstWhere')->andReturnNull();

        $order = [];
        $this->inboundService->shouldReceive('recordOutboundEvent')
            ->once()
            ->withArgs(function ($settings, OutboundSmsEventDTO $event, bool $fromHistory) use (&$order) {
                $order[] = $event->providerMessageId;

                return $fromHistory
                    && $event->status === SmsMessageStatus::DELIVERED
                    && $event->content === 'Halloween le 31/10'
                    && $event->timestamp === '2026-10-21T11:00:00+03:00';
            })
            ->andReturn(new SmsMessageDomainObject);
        $this->inboundService->shouldReceive('recordInbound')
            ->once()
            ->withArgs(function ($settings, InboundSmsDTO $sms, bool $fromHistory) use (&$order) {
                $order[] = $sms->providerMessageId;

                return $fromHistory && $sms->content === 'Stop';
            })
            ->andReturn(new SmsMessageDomainObject);

        $this->settingsRepository->shouldReceive('increment')->once()->with(1, 'history_import_count', 2);
        $this->settingsRepository->shouldReceive('updateFromArray')
            ->once()
            ->withArgs(fn (int $id, array $attributes) => $attributes['history_import_status'] === SmsHistoryImportStatus::COMPLETED->name);

        $this->assertNull($this->service->importPage(1, 0));
        $this->assertSame(['out-1', 'in-1'], $order);
    }

    public function test_known_messages_are_not_counted_twice(): void
    {
        $this->settingsRepository->shouldReceive('findFirst')->andReturn($this->settings());
        $this->client->shouldReceive('listMessageThreads')->andReturn([['contact' => '+262639123456']]);
        $this->client->shouldReceive('listMessages')->andReturn([
            $this->message('in-1', 'mobile-originated', 'received', 'Merci', '2026-10-21T12:00:00+03:00'),
            $this->message('out-1', 'mobile-terminated', 'sent', 'Halloween', '2026-10-21T11:00:00+03:00'),
        ]);
        $this->messageRepository->shouldReceive('findFirstWhere')->andReturn(new SmsMessageDomainObject);

        $this->inboundService->shouldNotReceive('recordInbound');
        $this->inboundService->shouldReceive('recordOutboundEvent')->once()->andReturn(new SmsMessageDomainObject);
        $this->settingsRepository->shouldNotReceive('increment');
        $this->settingsRepository->shouldReceive('updateFromArray')->once();

        $this->service->importPage(1, 0);
    }

    public function test_full_page_of_threads_returns_next_offset(): void
    {
        $this->settingsRepository->shouldReceive('findFirst')->andReturn($this->settings());
        $this->client->shouldReceive('listMessageThreads')
            ->with('uk_key', '+262639000000', 20, 20)
            ->andReturn(array_fill(0, 20, ['contact' => '']));
        $this->client->shouldNotReceive('listMessages');
        $this->settingsRepository->shouldNotReceive('updateFromArray');

        $this->assertSame(40, $this->service->importPage(1, 20));
    }

    public function test_messages_are_paginated_per_conversation(): void
    {
        $this->settingsRepository->shouldReceive('findFirst')->andReturn($this->settings());
        $this->client->shouldReceive('listMessageThreads')->andReturn([['contact' => '+262639123456']]);
        $this->client->shouldReceive('listMessages')
            ->with('uk_key', '+262639000000', '+262639123456', 0, 20)
            ->andReturn(array_map(
                fn (int $i) => $this->message('out-'.$i, 'mobile-terminated', 'sent', 'SMS '.$i, '2026-10-21T11:00:00+03:00'),
                range(1, 20),
            ));
        $this->client->shouldReceive('listMessages')
            ->with('uk_key', '+262639000000', '+262639123456', 20, 20)
            ->andReturn([$this->message('out-21', 'mobile-terminated', 'sent', 'SMS 21', '2026-10-21T10:00:00+03:00')]);
        $this->messageRepository->shouldReceive('findFirstWhere')->andReturnNull();
        $this->inboundService->shouldReceive('recordOutboundEvent')->times(21)->andReturn(new SmsMessageDomainObject);
        $this->settingsRepository->shouldReceive('increment')->once()->with(1, 'history_import_count', 21);
        $this->settingsRepository->shouldReceive('updateFromArray')->once();

        $this->service->importPage(1, 0);
    }

    public function test_cancelled_import_does_nothing(): void
    {
        $this->settingsRepository->shouldReceive('findFirst')
            ->andReturn($this->settings()->setHistoryImportStatus(SmsHistoryImportStatus::FAILED->name));
        $this->client->shouldNotReceive('listMessageThreads');

        $this->assertNull($this->service->importPage(1, 0));
    }

    public function test_gateway_error_marks_import_failed(): void
    {
        $this->settingsRepository->shouldReceive('findFirst')->andReturn($this->settings());
        $this->client->shouldReceive('listMessageThreads')->andThrow(new SmsGatewayException('Unauthorized', 401));
        $this->settingsRepository->shouldReceive('updateFromArray')
            ->once()
            ->withArgs(fn (int $id, array $attributes) => $attributes['history_import_status'] === SmsHistoryImportStatus::FAILED->name
                && $attributes['history_import_error'] === 'Unauthorized');

        $this->expectException(SmsGatewayException::class);

        $this->service->importPage(1, 0);
    }

    private function settings(): OrganizerSmsSettingDomainObject
    {
        return (new OrganizerSmsSettingDomainObject)
            ->setId(1)
            ->setAccountId(10)
            ->setOrganizerId(4)
            ->setApiKey('uk_key')
            ->setFromNumber('+262639000000')
            ->setHistoryImportStatus(SmsHistoryImportStatus::RUNNING->name)
            ->setHistoryImportStartedAt(now()->toDateTimeString());
    }

    private function message(string $id, string $type, string $status, string $content, string $timestamp): array
    {
        return [
            'id' => $id,
            'owner' => '+262639000000',
            'contact' => '+262639123456',
            'type' => $type,
            'status' => $status,
            'content' => $content,
            'request_id' => 'halloween-21-10',
            'order_timestamp' => $timestamp,
            'created_at' => $timestamp,
        ];
    }
}
