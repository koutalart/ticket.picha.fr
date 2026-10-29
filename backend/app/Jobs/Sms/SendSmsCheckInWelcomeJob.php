<?php

declare(strict_types=1);

namespace HiEvents\Jobs\Sms;

use HiEvents\Services\Domain\Sms\CheckInWelcome\SmsCheckInWelcomeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Psr\Log\LoggerInterface;
use Throwable;

class SendSmsCheckInWelcomeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(
        public readonly int $eventId,
        public readonly int $attendeeId,
    ) {}

    public function handle(SmsCheckInWelcomeService $service, LoggerInterface $logger): void
    {
        try {
            $service->sendForAttendee($this->eventId, $this->attendeeId);
        } catch (Throwable $exception) {
            $logger->error('Check-in welcome SMS failed', [
                'event_id' => $this->eventId,
                'attendee_id' => $this->attendeeId,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
