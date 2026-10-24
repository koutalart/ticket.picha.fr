<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\LiveChat\Refund\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;

class LiveChatRefundEligibilityDTO extends BaseDataObject
{
    public function __construct(
        public readonly OrderDomainObject $order,
        public readonly EventDomainObject $event,
        public readonly LiveChatRefundCoverageDTO $coverage,
    ) {}
}
