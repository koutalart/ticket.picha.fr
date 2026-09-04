<?php

declare(strict_types=1);

namespace Digit\Scan\Domain\DTO;

readonly class ScanOutcomeDTO
{
    public function __construct(
        public int $attendeeId,
        public string $result,
        public ?int $attendeeCheckInId = null,
        public array $messages = [],
        public ?string $firstScannedByDevice = null,
        public ?string $firstScannedAt = null,
        public ?string $firstScannedCheckInListName = null,
    ) {
    }

    public static function success(int $attendeeId, ?int $attendeeCheckInId): self
    {
        return new self($attendeeId, 'recorded', $attendeeCheckInId, []);
    }

    public static function duplicate(
        int $attendeeId,
        array $messages = [],
        ?string $firstScannedByDevice = null,
        ?string $firstScannedAt = null,
        ?string $firstScannedCheckInListName = null,
    ): self {
        return new self(
            $attendeeId,
            'duplicate',
            null,
            $messages,
            $firstScannedByDevice,
            $firstScannedAt,
            $firstScannedCheckInListName,
        );
    }

    public static function rejected(int $attendeeId, string $message): self
    {
        return new self($attendeeId, 'rejected', null, [$message]);
    }

    public function withResult(string $result): self
    {
        return new self(
            $this->attendeeId,
            $result,
            $this->attendeeCheckInId,
            $this->messages,
            $this->firstScannedByDevice,
            $this->firstScannedAt,
            $this->firstScannedCheckInListName,
        );
    }

    public function toArray(): array
    {
        return [
            'attendee_id' => $this->attendeeId,
            'result' => $this->result,
            'attendee_check_in_id' => $this->attendeeCheckInId,
            'messages' => $this->messages,
            'first_scanned_by_device' => $this->firstScannedByDevice,
            'first_scanned_at' => $this->firstScannedAt,
            'first_scanned_check_in_list_name' => $this->firstScannedCheckInListName,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            attendeeId: $data['attendee_id'],
            result: $data['result'],
            attendeeCheckInId: $data['attendee_check_in_id'] ?? null,
            messages: $data['messages'] ?? [],
            firstScannedByDevice: $data['first_scanned_by_device'] ?? null,
            firstScannedAt: $data['first_scanned_at'] ?? null,
            firstScannedCheckInListName: $data['first_scanned_check_in_list_name'] ?? null,
        );
    }
}
