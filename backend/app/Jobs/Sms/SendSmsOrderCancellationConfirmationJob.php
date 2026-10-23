<?php

declare(strict_types=1);

namespace HiEvents\Jobs\Sms;

use HiEvents\Services\Domain\Sms\CancellationConfirmation\SmsCancellationConfirmationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Psr\Log\LoggerInterface;
use Throwable;

class SendSmsOrderCancellationConfirmationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly int $orderId) {}

    public function handle(SmsCancellationConfirmationService $service, LoggerInterface $logger): void
    {
        try {
            $result = $service->sendForOrder($this->orderId);

            if ($result->sent + $result->failed > 0) {
                $logger->info('SMS cancellation confirmations processed', [
                    'order_id' => $this->orderId,
                    ...$result->toArray(),
                ]);
            }
        } catch (Throwable $exception) {
            $logger->error('SMS cancellation confirmations failed', [
                'order_id' => $this->orderId,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
