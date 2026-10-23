<?php

namespace HiEvents\Services\Domain\Question\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class QuestionAnswerCountDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $questionId,
        public readonly string $value,
        public readonly int $count,
    ) {}
}
