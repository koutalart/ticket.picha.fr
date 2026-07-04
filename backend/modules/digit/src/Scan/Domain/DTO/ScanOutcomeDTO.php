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
    ) {
    }

    public static function success(int $attendeeId, ?int $attendeeCheckInId): self
    {
        return new self($attendeeId, 'recorded', $attendeeCheckInId, []);
    }

    public static function duplicate(int $attendeeId, array $messages = []): self
    {
        return new self($attendeeId, 'duplicate', null, $messages);
    }

    public static function rejected(int $attendeeId, string $message): self
    {
        return new self($attendeeId, 'rejected', null, [$message]);
    }

    public function withResult(string $result): self
    {
        return new self($this->attendeeId, $result, $this->attendeeCheckInId, $this->messages);
    }

    public function toArray(): array
    {
        return [
            'attendee_id' => $this->attendeeId,
            'result' => $this->result,
            'attendee_check_in_id' => $this->attendeeCheckInId,
            'messages' => $this->messages,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            attendeeId: $data['attendee_id'],
            result: $data['result'],
            attendeeCheckInId: $data['attendee_check_in_id'] ?? null,
            messages: $data['messages'] ?? [],
        );
    }
}
