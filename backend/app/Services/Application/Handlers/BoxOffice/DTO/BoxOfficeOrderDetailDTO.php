<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\BoxOffice\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class BoxOfficeOrderDetailDTO extends BaseDataObject
{
    /**
     * @param  BoxOfficeAttendeeSearchResultDTO[]  $tickets
     */
    public function __construct(
        public readonly BoxOfficeOrderListItemDTO $order,
        public readonly array $tickets,
    ) {}
}
