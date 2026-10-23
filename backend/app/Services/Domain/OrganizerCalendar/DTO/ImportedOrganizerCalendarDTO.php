<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\OrganizerCalendar\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class ImportedOrganizerCalendarDTO extends BaseDataObject
{
    /**
     * @param  string[]  $missingFiles
     * @param  string[]  $unusedFiles
     */
    public function __construct(
        public readonly int $calendarId,
        public readonly bool $created,
        public readonly string $title,
        public readonly int $mediaCount,
        public readonly int $totalBytes,
        public readonly bool $frontHtmlImported,
        public readonly array $missingFiles,
        public readonly array $unusedFiles,
    ) {}
}
