<?php

namespace Tests\Unit\Services\Application\Handlers\Sms;

use HiEvents\DomainObjects\OrganizerSmsSettingDomainObject;
use HiEvents\Jobs\Sms\ImportSmsHistoryJob;
use HiEvents\Services\Application\Handlers\Sms\StartSmsHistoryImportHandler;
use HiEvents\Services\Domain\Sms\SmsHistoryImportService;
use HiEvents\Services\Domain\Sms\SmsSettingsService;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Tests\TestCase;

class StartSmsHistoryImportHandlerTest extends TestCase
{
    public function test_marks_the_import_as_started_and_queues_the_first_page(): void
    {
        Bus::fake();

        $settings = (new OrganizerSmsSettingDomainObject)->setId(12)->setOrganizerId(4);
        $settingsService = Mockery::mock(SmsSettingsService::class);
        $settingsService->shouldReceive('getOrCreate')->with(10, 4)->andReturn($settings);
        $settingsService->shouldReceive('getWebhookUrl')->andReturn('https://example.test/webhook');

        $importService = Mockery::mock(SmsHistoryImportService::class);
        $importService->shouldReceive('markStarted')->once()->with($settings)->andReturn($settings);

        $result = (new StartSmsHistoryImportHandler($settingsService, $importService))->handle(10, 4);

        $this->assertSame($settings, $result->settings);
        Bus::assertDispatched(ImportSmsHistoryJob::class, fn (ImportSmsHistoryJob $job) => $job->settingsId === 12 && $job->skip === 0);
    }
}
