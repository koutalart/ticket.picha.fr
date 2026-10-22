<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class SmsCancellationConfirmationResultDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $sent = 0,
        public readonly int $failed = 0,
        public readonly int $withoutValidPhone = 0,
        public readonly int $optedOut = 0,
        public readonly int $alreadySent = 0,
    ) {}
}
