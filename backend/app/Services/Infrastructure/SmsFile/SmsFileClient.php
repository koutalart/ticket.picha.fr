<?php

declare(strict_types=1);

namespace HiEvents\Services\Infrastructure\SmsFile;

use HiEvents\Exceptions\SmsFileRejectedException;
use HiEvents\Exceptions\SmsFileUnavailableException;
use HiEvents\Services\Infrastructure\SmsFile\DTO\SmsFileMessageDTO;
use HiEvents\Services\Infrastructure\SmsFile\DTO\SmsFileResultDTO;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Psr\Log\LoggerInterface;

/**
 * Client of the central SMS queue (picha-sms-file). The x-file-key is never logged.
 */
class SmsFileClient
{
    public const MAX_PER_REQUEST = 5000;

    public function __construct(
        private readonly ConfigRepository $config,
        private readonly LoggerInterface $logger,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) $this->config->get('services.sms_file.enabled', false)
            && $this->url() !== ''
            && $this->key() !== '';
    }

    /**
     * @throws SmsFileUnavailableException
     * @throws SmsFileRejectedException
     */
    public function enqueue(SmsFileMessageDTO $sms): SmsFileResultDTO
    {
        $response = $this->call('POST', 'v1/sms', $sms->payload(), (int) $this->config->get('services.sms_file.timeout_seconds', 10));

        return $this->result((array) $response->json());
    }

    /**
     * @param  array<int, SmsFileMessageDTO>  $messages
     * @return array<int, SmsFileResultDTO> in the order of the messages
     *
     * @throws SmsFileUnavailableException
     * @throws SmsFileRejectedException
     */
    public function enqueueMany(array $messages): array
    {
        if ($messages === []) {
            return [];
        }

        if (count($messages) > self::MAX_PER_REQUEST) {
            throw new SmsFileRejectedException(sprintf('%d SMS at most per request', self::MAX_PER_REQUEST));
        }

        $response = $this->call(
            'POST',
            'v1/sms',
            array_values(array_map(fn (SmsFileMessageDTO $sms) => $sms->payload(), $messages)),
            (int) $this->config->get('services.sms_file.batch_timeout_seconds', 30),
        );

        $rows = $response->json();
        if (! is_array($rows) || count($rows) !== count($messages)) {
            throw new SmsFileUnavailableException('Unexpected answer of the SMS queue');
        }

        return array_map(fn ($row) => $this->result(is_array($row) ? $row : []), array_values($rows));
    }

    /**
     * @throws SmsFileUnavailableException
     * @throws SmsFileRejectedException
     */
    public function find(int $id): SmsFileResultDTO
    {
        $response = $this->call('GET', 'v1/sms/'.$id, null, (int) $this->config->get('services.sms_file.timeout_seconds', 10));

        return $this->result((array) $response->json());
    }

    /**
     * @throws SmsFileUnavailableException
     */
    private function result(array $row): SmsFileResultDTO
    {
        $result = SmsFileResultDTO::fromResponse($row);
        if ($result === null) {
            throw new SmsFileUnavailableException('Unexpected answer of the SMS queue');
        }

        return $result;
    }

    /**
     * @throws SmsFileUnavailableException
     * @throws SmsFileRejectedException
     */
    private function call(string $method, string $path, ?array $body, int $timeout): Response
    {
        $request = Http::acceptJson()
            ->asJson()
            ->withHeaders(['x-file-key' => $this->key()])
            ->connectTimeout(max(1, (int) $this->config->get('services.sms_file.connect_timeout_seconds', 2)))
            ->timeout(max(1, $timeout));

        try {
            $response = $method === 'GET'
                ? $request->get($this->url().'/'.$path)
                : $request->post($this->url().'/'.$path, $body ?? []);
        } catch (ConnectionException $exception) {
            $this->logger->warning('SMS queue unreachable', ['method' => $method, 'path' => $path, 'error' => $exception->getMessage()]);

            throw new SmsFileUnavailableException(__('The SMS queue cannot be reached').' ('.$exception->getMessage().')', previous: $exception);
        }

        if ($response->successful()) {
            return $response;
        }

        $error = (string) ($response->json('erreur') ?? mb_substr($response->body(), 0, 300));
        $this->logger->warning('SMS queue refused the request', [
            'method' => $method,
            'path' => $path,
            'status' => $response->status(),
            'error' => $error,
        ]);

        if (in_array($response->status(), [400, 404, 413, 422], true)) {
            throw new SmsFileRejectedException($error, $response->status());
        }

        throw new SmsFileUnavailableException($error !== '' ? $error : __('The SMS queue cannot be reached'), $response->status());
    }

    private function url(): string
    {
        return rtrim((string) $this->config->get('services.sms_file.url', ''), '/');
    }

    private function key(): string
    {
        return (string) $this->config->get('services.sms_file.key', '');
    }
}
