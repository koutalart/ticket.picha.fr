<?php

namespace HiEvents\Resources\Question;

use HiEvents\DomainObjects\QuestionAnswerFileDomainObject;
use HiEvents\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * @mixin QuestionAnswerFileDomainObject
 */
class QuestionAnswerFileResourcePublic extends BaseResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getId(),
            'question_id' => $this->getQuestionId(),
            'original_filename' => $this->getOriginalFilename(),
            'size' => $this->getSize(),
        ];
    }
}
