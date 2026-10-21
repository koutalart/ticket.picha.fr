<?php

namespace HiEvents\Services\Application\Handlers\Question\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class QuestionAnswerDistributionOptionDTO extends BaseDataObject
{
    public function __construct(
        public readonly string $label,
        public readonly int $count,
        public readonly float $percentage,
    ) {}
}
