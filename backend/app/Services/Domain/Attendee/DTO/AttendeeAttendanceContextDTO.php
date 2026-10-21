<?php

namespace HiEvents\Services\Domain\Attendee\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;

class AttendeeAttendanceContextDTO extends BaseDataObject
{
    public function __construct(
        public readonly AttendeeDomainObject $attendee,
        public readonly EventDomainObject    $event,
        public readonly ?OrderDomainObject   $order,
    )
    {
    }
}
