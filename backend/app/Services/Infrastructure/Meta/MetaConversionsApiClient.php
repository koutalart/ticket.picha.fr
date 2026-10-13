<?php

declare(strict_types=1);

namespace HiEvents\Services\Infrastructure\Meta;

use HiEvents\Exceptions\MetaConversionsApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class MetaConversionsApiClient
{
    private const GRAPH_URL = 'https://graph.facebook.com';

    /**
     * @throws MetaConversionsApiException
     */
    public function sendEvents(string $pixelId, string $accessToken, array $events, ?string $testEventCode = null): int
    {
        $payload = ['data' => $events];

        if ($testEventCode) {
            $payload['test_event_code'] = $testEventCode;
        }

        $response = $this->send(fn (PendingRequest $request) => $request
            ->withQueryParameters(['access_token' => $accessToken])
            ->post($this->url($pixelId.'/events'), $payload));

        return (int) $response->json('events_received', 0);
    }

    /**
     * @throws MetaConversionsApiException
     */
    public function getPixelName(string $pixelId, string $accessToken): ?string
    {
        $response = $this->send(fn (PendingRequest $request) => $request
            ->get($this->url($pixelId), ['fields' => 'id,name', 'access_token' => $accessToken]));

        return $response->json('name');
    }

    /**
     * @throws MetaConversionsApiException
     */
    private function send(callable $callback): Response
    {
        try {
            /** @var Response $response */
            $response = $callback(Http::acceptJson()->timeout(config('services.meta.timeout_seconds', 10)));
        } catch (ConnectionException $exception) {
            throw new MetaConversionsApiException($exception->getMessage(), retryable: true, previous: $exception);
        }

        if ($response->failed()) {
            throw new MetaConversionsApiException(
                message: (string) ($response->json('error.message') ?? $response->body()),
                retryable: $response->serverError() || $response->status() === 429,
                code: $response->status(),
            );
        }

        return $response;
    }

    private function url(string $path): string
    {
        return sprintf('%s/%s/%s', self::GRAPH_URL, config('services.meta.graph_api_version'), $path);
    }
}
