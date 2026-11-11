<?php

namespace HiEvents\Services\Application\Handlers\Attendee\Booth\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class BoothAttendeeContactDTO extends BaseDataObject
{
    public function __construct(
        public readonly string $status,
        public readonly ?string $phone,
        public readonly ?string $email,
    ) {}
}
