<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Event\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class RecentPurchaseDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $quantity,
        public readonly int $minutes_ago,
    ) {}
}
