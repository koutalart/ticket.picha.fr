<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class SmsPhoneFixItemDTO extends BaseDataObject
{
    public function __construct(
        public readonly string $type,
        public readonly int $id,
        public readonly int $organizerId,
        public readonly string $from,
        public readonly string $to,
        public readonly string $action,
        public readonly ?int $mergedIntoId = null,
        public readonly int $movedMessages = 0,
    ) {}
}
