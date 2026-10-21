<?php

namespace HiEvents\Services\Application\Handlers\Attendee\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\DomainObjects\AttendeeDomainObject;

class AttendeeAttendanceStatusDTO extends BaseDataObject
{
    public function __construct(
        public readonly AttendeeDomainObject $attendee,
        public readonly ?string              $blockingReason,
    )
    {
    }
}
