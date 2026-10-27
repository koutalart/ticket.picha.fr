<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Order\RefundProtection\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class RefundProtectionOfferDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $product_id,
        public readonly string $title,
        public readonly ?string $description,
        public readonly int $quantity,
        public readonly bool $is_per_ticket,
        public readonly float $total_before_additions,
        public readonly float $total_tax,
        public readonly float $total_fee,
        public readonly float $total_gross,
        public readonly string $currency,
        public readonly bool $is_selected,
        public readonly bool $is_locked,
        public readonly int $mandatory_quantity = 0,
        public readonly float $mandatory_total_gross = 0.0,
        public readonly ?float $mandatory_above = null,
        public readonly bool $is_required_by_organizer = false,
    ) {}
}
