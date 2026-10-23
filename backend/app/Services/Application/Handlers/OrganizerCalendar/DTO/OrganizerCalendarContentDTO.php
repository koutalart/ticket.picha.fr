<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\OrganizerCalendar\DTO;

use Carbon\CarbonInterface;
use HiEvents\DataTransferObjects\BaseDataObject;

class OrganizerCalendarContentDTO extends BaseDataObject
{
    public function __construct(
        public readonly array $content,
        public readonly CarbonInterface $expiresAt,
    ) {}
}
