<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\BoxOffice\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class BoxOfficeSalesPageDTO extends BaseDataObject
{
    /**
     * @param  BoxOfficeSaleListItemDTO[]  $items
     */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
        public readonly int $current_page,
        public readonly int $last_page,
        public readonly int $per_page,
    ) {}
}
