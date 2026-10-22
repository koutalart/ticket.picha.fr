<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Attendee\AttendanceAccess\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class VerifyAttendanceAccessCodeDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $eventId,
        public readonly string $contact,
        public readonly string $code,
        public readonly string $ipAddress,
    ) {}
}
