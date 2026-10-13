<?php

declare(strict_types=1);

namespace HiEvents\Jobs\Meta;

use HiEvents\Exceptions\MetaConversionsApiException;
use HiEvents\Services\Domain\Meta\MetaPurchaseConversionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Psr\Log\LoggerInterface;

class SendMetaPurchaseConversionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    public array $backoff = [60, 300, 1800];

    public function __construct(public readonly int $orderId) {}

    /**
     * @throws MetaConversionsApiException
     */
    public function handle(MetaPurchaseConversionService $service, LoggerInterface $logger): void
    {
        try {
            $service->sendPurchase($this->orderId);
        } catch (MetaConversionsApiException $exception) {
            $logger->warning('Meta Conversions API purchase failed', [
                'order_id' => $this->orderId,
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
