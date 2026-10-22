<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Attendee\AttendanceAccess\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class AttendanceAccessLinkDTO extends BaseDataObject
{
    public function __construct(
        public readonly string $short_id,
        public readonly string $first_name,
        public readonly string $code,
    ) {}
}
