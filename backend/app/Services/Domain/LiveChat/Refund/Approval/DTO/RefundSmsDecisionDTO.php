<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\LiveChat\Refund\Approval\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\DomainObjects\Enums\RefundDecision;

class RefundSmsDecisionDTO extends BaseDataObject
{
    public function __construct(
        public readonly RefundDecision $decision,
        public readonly int $requestId,
    ) {}
}
