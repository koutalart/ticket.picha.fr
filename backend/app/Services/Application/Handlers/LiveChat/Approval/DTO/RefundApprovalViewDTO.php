<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\LiveChat\Approval\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class RefundApprovalViewDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $id,
        public readonly string $reference,
        public readonly string $status,
        public readonly ?string $decision,
        public readonly int $organizerId,
        public readonly string $organizerName,
        public readonly ?string $eventTitle,
        public readonly string $customerName,
        public readonly ?string $orderReference,
        public readonly float $amount,
        public readonly string $currency,
        public readonly string $amountText,
        public readonly ?string $requestedAt,
        public readonly ?string $decidedAt,
        public readonly ?string $decidedByChannel,
        public readonly ?string $decidedByName,
        public readonly bool $canDecide,
    ) {}
}
