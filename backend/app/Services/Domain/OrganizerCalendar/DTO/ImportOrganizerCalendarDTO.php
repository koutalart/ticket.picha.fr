<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\OrganizerCalendar\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class ImportOrganizerCalendarDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $organizerId,
        public readonly string $slug,
        public readonly string $directory,
        public readonly ?string $title = null,
        public readonly ?string $phone = null,
        public readonly bool $allowMissingMedia = false,
    ) {}
}
