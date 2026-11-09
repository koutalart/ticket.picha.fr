<?php

namespace HiEvents\Services\Application\Handlers\Attendee\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\Services\Domain\Attendee\DTO\AttendeeGuestAvailabilityDTO;

class AttendeeAttendanceStatusDTO extends BaseDataObject
{
    public function __construct(
        public readonly AttendeeDomainObject $attendee,
        public readonly ?string              $blockingReason,
        public readonly ?AttendeeGuestAvailabilityDTO $guestAvailability = null,
    )
    {
    }
}
