<?php

declare(strict_types=1);

namespace Digit\Bracelets\Domain\DTO;

use Digit\Bracelets\Domain\Enums\BraceletStatus;
use Digit\Bracelets\Domain\Models\DigitBracelet;

/**
 * Read-only projection of a DigitBracelet, safe to return from HTTP actions.
 * Deliberately does not include event/account internals beyond IDs, and
 * never includes anything about the underlying attendee beyond its ID -
 * name/category/etc. stay native, fetched separately by the caller if
 * needed (see risk "PII leakage via lookup endpoint" in the design docs).
 */
readonly class BraceletDTO
{
    public function __construct(
        public int $id,
        public string $code,
        public int $eventId,
        public int $accountId,
        public ?int $attendeeId,
        public ?string $batchLabel,
        public BraceletStatus $status,
        public ?string $printedAt,
        public ?string $assignedAt,
        public ?string $revokedAt,
        public ?string $compromisedAt,
    ) {
    }

    public static function fromModel(DigitBracelet $bracelet): self
    {
        return new self(
            id: $bracelet->id,
            code: $bracelet->code,
            eventId: $bracelet->event_id,
            accountId: $bracelet->account_id,
            attendeeId: $bracelet->attendee_id,
            batchLabel: $bracelet->batch_label,
            status: $bracelet->status,
            printedAt: $bracelet->printed_at?->toIso8601String(),
            assignedAt: $bracelet->assigned_at?->toIso8601String(),
            revokedAt: $bracelet->revoked_at?->toIso8601String(),
            compromisedAt: $bracelet->compromised_at?->toIso8601String(),
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'event_id' => $this->eventId,
            'account_id' => $this->accountId,
            'attendee_id' => $this->attendeeId,
            'batch_label' => $this->batchLabel,
            'status' => $this->status->value,
            'printed_at' => $this->printedAt,
            'assigned_at' => $this->assignedAt,
            'revoked_at' => $this->revokedAt,
            'compromised_at' => $this->compromisedAt,
        ];
    }
}
