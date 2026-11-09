<?php

namespace HiEvents\Services\Domain\Attendee\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class AttendeeGuestAvailabilityDTO extends BaseDataObject
{
    public function __construct(
        public readonly bool    $canAddGuest,
        public readonly int     $placesRemaining,
        public readonly ?string $reason,
        public readonly ?int    $maxPerOrder = null,
    )
    {
    }
}
