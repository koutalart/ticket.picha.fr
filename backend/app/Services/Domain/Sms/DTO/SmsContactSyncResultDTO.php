<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class SmsContactSyncResultDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $accounts = 0,
        public readonly int $created = 0,
        public readonly int $updated = 0,
        public readonly int $unchanged = 0,
        public readonly int $failed = 0,
    ) {}

    public function add(self $other): self
    {
        return new self(
            accounts: $this->accounts + $other->accounts,
            created: $this->created + $other->created,
            updated: $this->updated + $other->updated,
            unchanged: $this->unchanged + $other->unchanged,
            failed: $this->failed + $other->failed,
        );
    }
}
