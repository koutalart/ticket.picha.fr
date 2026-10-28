<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Event\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class RecentPurchasesDTO extends BaseDataObject
{
    /**
     * @param  RecentPurchaseDTO[]  $purchases
     */
    public function __construct(
        public readonly array $purchases,
    ) {}
}
