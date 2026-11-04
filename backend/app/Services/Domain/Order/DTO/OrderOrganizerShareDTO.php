<?php

namespace HiEvents\Services\Domain\Order\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class OrderOrganizerShareDTO extends BaseDataObject
{
    public function __construct(
        public float $totalGross,
        public float $serviceFees,
        public float $refundProtection,
        public float $organizerSales,
        public float $refundableToBuyer,
    )
    {
    }
}
