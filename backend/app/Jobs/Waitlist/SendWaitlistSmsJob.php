<?php

declare(strict_types=1);

namespace HiEvents\Jobs\Waitlist;

use HiEvents\Helper\SmsSendingHours;
use HiEvents\Services\Domain\Sms\Waitlist\SmsWaitlistService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Waiting list SMS, sent between 8:00 and 20:00 in the event timezone, otherwise the next morning.
 */
class SendWaitlistSmsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const CONFIRMATION = 'CONFIRMATION';

    public const OFFER = 'OFFER';

    public int $tries = 1;

    public function __construct(
        public readonly int $entryId,
        public readonly string $type,
        public readonly ?string $orderShortId = null,
        public readonly ?string $sessionIdentifier = null,
    ) {}

    public function handle(SmsWaitlistService $service, LoggerInterface $logger): void
    {
        try {
            $entry = $service->findEntry($this->entryId);
            if ($entry === null || $entry->getPhone() === null) {
                return;
            }

            $nextAllowed = SmsSendingHours::nextAllowedTime(Carbon::now(), $service->timezoneForEntry($entry));
            if ($nextAllowed !== null) {
                self::dispatch($this->entryId, $this->type, $this->orderShortId, $this->sessionIdentifier)
                    ->delay($nextAllowed->addMinutes(random_int(0, 25)));

                return;
            }

            if ($this->type === self::OFFER) {
                $service->sendOffer($entry, (string) $this->orderShortId, (string) $this->sessionIdentifier);

                return;
            }

            $service->sendConfirmation($entry);
        } catch (Throwable $exception) {
            $logger->warning('Waiting list SMS not sent', [
                'waitlist_entry_id' => $this->entryId,
                'type' => $this->type,
                'reason' => class_basename($exception),
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
