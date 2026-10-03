<?php

namespace HiEvents\Services\Application\Handlers\Attendee\DTO;

use HiEvents\DataTransferObjects\BaseDTO;

class CreateAttendeeDTO extends BaseDTO
{
    public function __construct(
        public readonly string $first_name,
        public readonly string $last_name,
        public readonly string $email,
        public readonly int    $product_id,
        public readonly int    $event_id,
        public readonly bool   $send_confirmation_email,
        public readonly string $locale,
        public readonly ?int   $product_price_id = null,
        public readonly bool   $is_free = false,
    )
    {
    }
}
