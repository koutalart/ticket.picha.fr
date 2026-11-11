<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Product\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class SetProductSalesPausedDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $eventId,
        public readonly int $productId,
        public readonly bool $salesPaused,
    ) {}
}
