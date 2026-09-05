<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\BoxOffice\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;

class BoxOfficeSaleResultDTO extends BaseDataObject
{
    public function __construct(
        public readonly int                  $saleId,
        public readonly AttendeeDomainObject $attendee,
        public readonly OrderDomainObject    $order,
    )
    {
    }
}
