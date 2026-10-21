<?php

namespace HiEvents\Services\Application\Handlers\Question\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use Illuminate\Http\UploadedFile;

class UploadQuestionAnswerFileDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $eventId,
        public readonly string $orderShortId,
        public readonly int $questionId,
        public readonly UploadedFile $file,
    ) {}
}
