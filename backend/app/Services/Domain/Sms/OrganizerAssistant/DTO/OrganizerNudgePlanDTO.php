<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms\OrganizerAssistant\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class OrganizerNudgePlanDTO extends BaseDataObject
{
    public const STATE_NOTHING_AWAITED = 'nothing_awaited';

    public const STATE_ANSWERED = 'answered';

    public const STATE_OPTED_OUT = 'opted_out';

    public const STATE_SUPERVISOR = 'supervisor';

    public const STATE_WAITING = 'waiting';

    public const STATE_OUTSIDE_HOURS = 'outside_hours';

    public const STATE_DUE = 'due';

    public const STATE_SENT = 'sent';

    public const STATE_ALREADY_CLAIMED = 'already_claimed';

    public const STATE_FAILED = 'failed';

    /**
     * Dates are in the local time of the organizer (Y-m-d H:i).
     */
    public function __construct(
        public readonly int $organizerId,
        public readonly int $contactId,
        public readonly string $phone,
        public readonly ?string $contactName,
        public readonly string $state,
        public readonly ?int $awaitedMessageId = null,
        public readonly ?string $awaitedAt = null,
        public readonly ?string $nextNudgeAt = null,
        public readonly ?int $variant = null,
        public readonly ?string $text = null,
    ) {}

    public function withState(string $state): self
    {
        return new self(
            $this->organizerId,
            $this->contactId,
            $this->phone,
            $this->contactName,
            $state,
            $this->awaitedMessageId,
            $this->awaitedAt,
            $this->nextNudgeAt,
            $this->variant,
            $this->text,
        );
    }
}
