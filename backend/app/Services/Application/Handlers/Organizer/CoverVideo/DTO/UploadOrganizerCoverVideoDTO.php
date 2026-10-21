<?php

namespace HiEvents\Services\Application\Handlers\Organizer\CoverVideo\DTO;

use Illuminate\Http\UploadedFile;

class UploadOrganizerCoverVideoDTO
{
    public function __construct(
        public readonly int $organizerId,
        public readonly int $accountId,
        public readonly UploadedFile $video,
        public readonly bool $mobile = false,
    ) {}
}
