<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\BoxOffice\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class CreateBoxOfficeSaleItemDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $product_id,
        public readonly int $product_price_id,
        public readonly int $quantity,
    ) {}
}
