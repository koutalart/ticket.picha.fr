<?php

declare(strict_types=1);

namespace HiEvents\Repository\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class RecentTicketPurchaseDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $quantity,
        public readonly string $purchasedAt,
    ) {}
}
