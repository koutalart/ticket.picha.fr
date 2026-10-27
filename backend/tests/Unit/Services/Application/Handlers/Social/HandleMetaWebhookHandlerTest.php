<?php

namespace Tests\Unit\Services\Application\Handlers\Social;

use HiEvents\Jobs\Social\ProcessMetaWebhookJob;
use HiEvents\Services\Application\Handlers\Social\HandleMetaWebhookHandler;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class HandleMetaWebhookHandlerTest extends TestCase
{
    public function test_signed_payload_is_queued_and_unsigned_one_refused(): void
    {
        Bus::fake();
        config(['services.meta.app_secret' => 'app-secret']);
        $handler = app(HandleMetaWebhookHandler::class);
        $body = json_encode(['object' => 'instagram', 'entry' => [['id' => '1784']]]);

        $this->assertFalse($handler->receive($body, 'sha256=forged'));
        Bus::assertNotDispatched(ProcessMetaWebhookJob::class);

        $this->assertTrue($handler->receive($body, 'sha256='.hash_hmac('sha256', $body, 'app-secret')));
        Bus::assertDispatched(ProcessMetaWebhookJob::class, fn (ProcessMetaWebhookJob $job) => $job->payload['object'] === 'instagram');
    }
}
