<?php

declare(strict_types=1);

namespace HiEvents\Jobs\Meta;

use HiEvents\Exceptions\MetaConversionsApiException;
use HiEvents\Services\Domain\Meta\DTO\MetaServerEventDTO;
use HiEvents\Services\Domain\Meta\MetaServerEventService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Psr\Log\LoggerInterface;

class SendMetaServerEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [60, 600];

    public function __construct(public readonly MetaServerEventDTO $event) {}

    /**
     * @throws MetaConversionsApiException
     */
    public function handle(MetaServerEventService $service, LoggerInterface $logger): void
    {
        try {
            $service->send($this->event);
        } catch (MetaConversionsApiException $exception) {
            $logger->warning('Meta Conversions API event failed', [
                'event_name' => $this->event->eventName,
                'event_id' => $this->event->eventId,
                'status' => $exception->getCode(),
                'message' => $exception->getMessage(),
                'retryable' => $exception->isRetryable(),
            ]);

            if ($exception->isRetryable()) {
                throw $exception;
            }
        }
    }
}
