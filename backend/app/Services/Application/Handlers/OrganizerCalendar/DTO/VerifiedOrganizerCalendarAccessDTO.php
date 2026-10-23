<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\OrganizerCalendar\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\Services\Domain\OrganizerCalendar\DTO\OrganizerCalendarAccessGrantDTO;

class VerifiedOrganizerCalendarAccessDTO extends BaseDataObject
{
    public function __construct(
        public readonly OrganizerCalendarAccessGrantDTO $grant,
        public readonly bool $admin,
    ) {}
}
