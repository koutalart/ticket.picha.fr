<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Loyalty\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class LoyaltyLocationDTO extends BaseDataObject
{
    public function __construct(
        public float $latitude,
        public float $longitude,
        public ?string $relevant_text = null,
    ) {}
}
