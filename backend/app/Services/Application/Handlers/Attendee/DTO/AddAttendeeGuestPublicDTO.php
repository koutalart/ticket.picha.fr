<?php

namespace HiEvents\Services\Application\Handlers\Attendee\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class AddAttendeeGuestPublicDTO extends BaseDataObject
{
    public function __construct(
        public readonly int     $eventId,
        public readonly string  $attendeeShortId,
        public readonly ?string $code,
        public readonly string  $firstName,
        public readonly string  $lastName,
        public readonly ?string $phone,
    )
    {
    }
}
