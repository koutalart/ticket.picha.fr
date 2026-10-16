<?php

namespace HiEvents\Services\Application\Handlers\Event\CoverVideo\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use Illuminate\Http\UploadedFile;

class UploadEventCoverVideoDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $eventId,
        public readonly int $accountId,
        public readonly UploadedFile $video,
    ) {}
}
