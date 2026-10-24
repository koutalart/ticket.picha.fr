<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\LiveChat\Refund\Approval\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class RefundApprovalRunResultDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $validatorReminders = 0,
        public readonly int $customerUpdates = 0,
        public readonly int $escalations = 0,
    ) {}
}
