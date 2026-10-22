<?php

declare(strict_types=1);

namespace HiEvents\Jobs\Sms;

use HiEvents\Services\Domain\Sms\RegistrationConfirmation\SmsRegistrationConfirmationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Psr\Log\LoggerInterface;
use Throwable;

class SendSmsRegistrationConfirmationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly int $orderId) {}

    public function handle(SmsRegistrationConfirmationService $service, LoggerInterface $logger): void
    {
        try {
            $result = $service->sendForOrder($this->orderId);

            if ($result->sent + $result->failed > 0) {
                $logger->info('SMS registration confirmations processed', [
                    'order_id' => $this->orderId,
                    ...$result->toArray(),
                ]);
            }
        } catch (Throwable $exception) {
            $logger->error('SMS registration confirmations failed', [
                'order_id' => $this->orderId,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
