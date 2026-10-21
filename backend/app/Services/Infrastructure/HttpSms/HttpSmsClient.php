<?php

declare(strict_types=1);

namespace HiEvents\Services\Infrastructure\HttpSms;

use HiEvents\Exceptions\SmsGatewayException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class HttpSmsClient
{
    /**
     * @throws SmsGatewayException
     */
    public function sendMessage(string $apiKey, string $from, string $to, string $content, string $requestId): ?string
    {
        $response = $this->send($apiKey, fn (PendingRequest $request) => $request->post($this->url('messages/send'), [
            'from' => $from,
            'to' => $to,
            'content' => $content,
            'request_id' => $requestId,
        ]));

        $id = $response->json('data.id');

        return is_string($id) ? $id : null;
    }

    /**
     * @return string[]
     *
     * @throws SmsGatewayException
     */
    public function listPhoneNumbers(string $apiKey): array
    {
        $response = $this->send($apiKey, fn (PendingRequest $request) => $request->get($this->url('phones'), ['limit' => 20]));

        return collect($response->json('data', []))
            ->pluck('phone_number')
            ->filter(fn ($number) => is_string($number) && $number !== '')
            ->values()
            ->all();
    }

    /**
     * @throws SmsGatewayException
     */
    private function send(string $apiKey, callable $callback): Response
    {
        try {
            /** @var Response $response */
            $response = $callback(
                Http::acceptJson()
                    ->asJson()
                    ->withHeaders(['x-api-key' => $apiKey])
                    ->timeout(config('services.httpsms.timeout_seconds', 15))
            );
        } catch (ConnectionException $exception) {
            throw new SmsGatewayException($exception->getMessage(), previous: $exception);
        }

        if ($response->failed()) {
            throw new SmsGatewayException(
                message: (string) ($response->json('message') ?? $response->body()),
                code: $response->status(),
            );
        }

        return $response;
    }

    private function url(string $path): string
    {
        return rtrim((string) config('services.httpsms.base_url'), '/').'/'.$path;
    }
}
