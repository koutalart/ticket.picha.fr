<?php

namespace HiEvents\Services\Domain\Attendee\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class AttendeeGuestDataDTO extends BaseDataObject
{
    public function __construct(
        public readonly string  $firstName,
        public readonly string  $lastName,
        public readonly ?string $phone,
    )
    {
    }
}
