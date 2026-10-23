<?php

namespace HiEvents\Services\Application\Handlers\Attendee\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class AttendeeAttendancePublicDTO extends BaseDataObject
{
    public function __construct(
        public readonly int     $eventId,
        public readonly string  $attendeeShortId,
        public readonly ?string $code,
    )
    {
    }
}
