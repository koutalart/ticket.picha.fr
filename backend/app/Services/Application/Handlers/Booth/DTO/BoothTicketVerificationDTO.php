<?php

namespace HiEvents\Services\Application\Handlers\Booth\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class BoothTicketVerificationDTO extends BaseDataObject
{
    public function __construct(
        public bool $valid,
        public ?string $status,
        public int $event_id,
        public ?int $product_id,
    )
    {
    }
}
