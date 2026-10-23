<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class SmsWatchdogRunResultDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $detected = 0,
        public readonly int $alerted = 0,
        public readonly int $resolved = 0,
        public readonly bool $recipientMissing = false,
    ) {}
}
