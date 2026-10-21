<?php

namespace HiEvents\Models;

use HiEvents\DomainObjects\Generated\QuestionAnswerFileDomainObjectAbstract;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class QuestionAnswerFile extends BaseModel
{
    use SoftDeletes;

    protected function getFillableFields(): array
    {
        return [
            QuestionAnswerFileDomainObjectAbstract::EVENT_ID,
            QuestionAnswerFileDomainObjectAbstract::ORDER_ID,
            QuestionAnswerFileDomainObjectAbstract::QUESTION_ID,
            QuestionAnswerFileDomainObjectAbstract::QUESTION_ANSWER_ID,
            QuestionAnswerFileDomainObjectAbstract::DISK,
            QuestionAnswerFileDomainObjectAbstract::PATH,
            QuestionAnswerFileDomainObjectAbstract::ORIGINAL_FILENAME,
            QuestionAnswerFileDomainObjectAbstract::MIME_TYPE,
            QuestionAnswerFileDomainObjectAbstract::SIZE,
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function question_answer(): BelongsTo
    {
        return $this->belongsTo(QuestionAnswer::class);
    }
}
