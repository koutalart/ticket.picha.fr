<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class SmsUnattributedBackfillItemDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $unattributedId,
        public readonly string $phone,
        public readonly ?int $organizerId,
        public readonly ?int $smsMessageId = null,
        public readonly ?string $lastSentAt = null,
    ) {}
}
