<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\BoxOffice\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;

class BoxOfficeCartOrderDTO extends BaseDataObject
{
    /**
     * @param  AttendeeDomainObject[]  $attendees
     */
    public function __construct(
        public readonly OrderDomainObject $order,
        public readonly array $attendees,
    ) {}
}
