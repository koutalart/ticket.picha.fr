<?php

namespace Tests\Unit\Services\Infrastructure\Meta;

use HiEvents\Exceptions\MetaConversionsApiException;
use HiEvents\Services\Infrastructure\Meta\MetaConversionsApiClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MetaConversionsApiClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.meta.graph_api_version' => 'v24.0']);
    }

    public function test_sends_events_to_pixel_endpoint(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1, 'fbtrace_id' => 'x'])]);

        $received = (new MetaConversionsApiClient)->sendEvents('123', 'secret-token', [['event_name' => 'Purchase']], 'TEST1');

        $this->assertSame(1, $received);
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://graph.facebook.com/v24.0/123/events?access_token=secret-token')
            && $request['data'] === [['event_name' => 'Purchase']]
            && $request['test_event_code'] === 'TEST1');
    }

    public function test_client_errors_are_not_retryable(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid OAuth access token']], 400)]);

        try {
            (new MetaConversionsApiClient)->sendEvents('123', 'bad', [['event_name' => 'Purchase']]);
            $this->fail('Expected exception');
        } catch (MetaConversionsApiException $exception) {
            $this->assertFalse($exception->isRetryable());
            $this->assertSame('Invalid OAuth access token', $exception->getMessage());
            $this->assertSame(400, $exception->getCode());
        }
    }

    public function test_server_errors_and_rate_limits_are_retryable(): void
    {
        foreach ([500, 429] as $status) {
            Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'oops']], $status)]);

            try {
                (new MetaConversionsApiClient)->getPixelName('123', 'token');
                $this->fail('Expected exception');
            } catch (MetaConversionsApiException $exception) {
                $this->assertTrue($exception->isRetryable(), (string) $status);
            }
        }
    }

    public function test_returns_pixel_name(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['id' => '123', 'name' => 'PICHA Pixel'])]);

        $this->assertSame('PICHA Pixel', (new MetaConversionsApiClient)->getPixelName('123', 'token'));
    }
}
