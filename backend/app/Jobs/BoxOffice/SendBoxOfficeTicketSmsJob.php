<?php

declare(strict_types=1);

namespace HiEvents\Jobs\BoxOffice;

use HiEvents\Services\Domain\Sms\AttendeeTicket\SmsAttendeeTicketService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A ticket sold on site goes out by SMS right away: the buyer is standing at the door, so no quiet hours.
 */
class SendBoxOfficeTicketSmsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /**
     * @param  int[]  $attendeeIds
     */
    public function __construct(
        public readonly array $attendeeIds,
        public readonly int $eventId,
    ) {}

    public function handle(SmsAttendeeTicketService $smsService, LoggerInterface $logger): void
    {
        foreach ($this->attendeeIds as $attendeeId) {
            try {
                $smsService->send($attendeeId, $this->eventId);
            } catch (Throwable $exception) {
                $logger->warning('Box office ticket SMS not sent', [
                    'attendee_id' => $attendeeId,
                    'reason' => class_basename($exception),
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }
}
