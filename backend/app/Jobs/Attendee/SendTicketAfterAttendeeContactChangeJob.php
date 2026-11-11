<?php

declare(strict_types=1);

namespace HiEvents\Jobs\Attendee;

use HiEvents\Services\Application\Handlers\Attendee\DTO\ResendAttendeeTicketDTO;
use HiEvents\Services\Application\Handlers\Attendee\ResendAttendeeTicketHandler;
use HiEvents\Services\Domain\Sms\AttendeeTicket\SmsAttendeeTicketService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * After the organizer changes an attendee's name, e-mail or phone, the ticket goes to the new contact:
 * by e-mail right away, by SMS between 8:00 and 20:00 in the event timezone.
 */
class SendTicketAfterAttendeeContactChangeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly int $attendeeId,
        public readonly int $eventId,
        public readonly bool $sendEmail,
        public readonly bool $sendSms,
    ) {}

    public function handle(
        ResendAttendeeTicketHandler $emailHandler,
        SmsAttendeeTicketService $smsService,
        LoggerInterface $logger,
    ): void {
        if ($this->sendEmail) {
            try {
                $emailHandler->handle(new ResendAttendeeTicketDTO($this->attendeeId, $this->eventId));
            } catch (Throwable $exception) {
                $logger->warning('Ticket e-mail after attendee change not sent', [
                    'attendee_id' => $this->attendeeId,
                    'reason' => class_basename($exception),
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        if (! $this->sendSms) {
            return;
        }

        try {
            $nextAllowed = SmsAttendeeTicketService::nextAllowedTime(Carbon::now(), $smsService->timezoneForEvent($this->eventId));
            if ($nextAllowed !== null) {
                self::dispatch($this->attendeeId, $this->eventId, false, true)
                    ->delay($nextAllowed->addMinutes(random_int(0, 25)));

                return;
            }

            $smsService->send($this->attendeeId, $this->eventId);
        } catch (Throwable $exception) {
            $logger->warning('Ticket SMS after attendee change not sent', [
                'attendee_id' => $this->attendeeId,
                'reason' => class_basename($exception),
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
