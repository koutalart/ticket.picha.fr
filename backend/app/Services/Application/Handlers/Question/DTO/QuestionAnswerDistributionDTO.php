<?php

namespace HiEvents\Services\Application\Handlers\Question\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class QuestionAnswerDistributionDTO extends BaseDataObject
{
    /**
     * @param  QuestionAnswerDistributionOptionDTO[]  $options
     */
    public function __construct(
        public readonly int $question_id,
        public readonly string $title,
        public readonly string $type,
        public readonly string $belongs_to,
        public readonly int $total_responses,
        public readonly array $options,
    ) {}
}
