<?php

namespace HiEvents\Services\Application\Handlers\Organizer\Font\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\DomainObjects\Enums\OrganizerFontWeight;
use Illuminate\Http\UploadedFile;

class UploadOrganizerFontDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $organizerId,
        public readonly int $accountId,
        public readonly string $familyName,
        public readonly OrganizerFontWeight $weight,
        public readonly UploadedFile $file,
    ) {}
}
