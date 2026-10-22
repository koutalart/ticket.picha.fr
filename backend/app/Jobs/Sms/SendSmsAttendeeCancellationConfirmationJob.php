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

class SendSmsAttendeeCancellationConfirmationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly int $attendeeId) {}

    public function handle(SmsCancellationConfirmationService $service, LoggerInterface $logger): void
    {
        try {
            $result = $service->sendForAttendee($this->attendeeId);

            if ($result->sent + $result->failed > 0) {
                $logger->info('SMS cancellation confirmation processed', [
                    'attendee_id' => $this->attendeeId,
                    ...$result->toArray(),
                ]);
            }
        } catch (Throwable $exception) {
            $logger->error('SMS cancellation confirmation failed', [
                'attendee_id' => $this->attendeeId,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
