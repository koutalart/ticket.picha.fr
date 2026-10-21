<?php

namespace HiEvents\Jobs\Contact;

use HiEvents\Services\Domain\Contact\ContactSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class SyncContactsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public readonly ?int $orderId = null,
        public readonly ?int $attendeeId = null,
        public readonly ?int $checkInId = null,
    ) {}

    public function handle(ContactSyncService $contactSyncService): void
    {
        match (true) {
            $this->orderId !== null => $contactSyncService->syncForOrder($this->orderId),
            $this->attendeeId !== null => $contactSyncService->syncForAttendee($this->attendeeId),
            $this->checkInId !== null => $contactSyncService->syncForCheckIn($this->checkInId),
            default => null,
        };
    }
}
