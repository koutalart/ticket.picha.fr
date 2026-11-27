<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Loyalty\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class EnrollLoyaltyCardDTO extends BaseDataObject
{
    public function __construct(
        public int $organizer_id,
        public string $first_name,
        public ?string $email = null,
        public ?string $phone = null,
    ) {}
}
