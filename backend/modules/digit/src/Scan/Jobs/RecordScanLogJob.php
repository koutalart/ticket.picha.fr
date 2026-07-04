<?php

declare(strict_types=1);

namespace Digit\Scan\Jobs;

use Digit\Scan\Domain\DTO\ScanOutcomeDTO;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class RecordScanLogJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public ScanOutcomeDTO $outcome,
        public string $deviceIdentifier,
        public ?int $accountId = null,
        public ?int $eventId = null,
        public ?int $checkInListId = null,
        public ?string $idempotencyKey = null,
    ) {
    }

    public function handle(): void
    {
        DB::table('scan_logs')->insert([
            'account_id' => $this->accountId,
            'event_id' => $this->eventId,
            'check_in_list_id' => $this->checkInListId,
            'attendee_id' => $this->outcome->attendeeId,
            'scanned_by_user_id' => null,
            'result' => $this->outcome->result,
            'idempotency_key' => $this->idempotencyKey,
            'device_identifier' => $this->deviceIdentifier,
            'scanned_at' => now(),
            'metadata' => json_encode(['messages' => $this->outcome->messages]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
