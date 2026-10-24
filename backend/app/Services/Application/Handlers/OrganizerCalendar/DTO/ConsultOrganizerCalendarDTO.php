<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\OrganizerCalendar\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class ConsultOrganizerCalendarDTO extends BaseDataObject
{
    public function __construct(
        public readonly string $slug,
        public readonly string $visitorName,
        public readonly string $deviceId,
        public readonly string $ipAddress,
        public readonly ?string $userAgent,
    ) {}
}
