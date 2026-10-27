<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\LiveChatQuestionDomainObject;
use HiEvents\Models\LiveChatQuestion;
use HiEvents\Repository\Interfaces\LiveChatQuestionRepositoryInterface;

/**
 * @extends BaseRepository<LiveChatQuestionDomainObject>
 */
class LiveChatQuestionRepository extends BaseRepository implements LiveChatQuestionRepositoryInterface
{
    protected function getModel(): string
    {
        return LiveChatQuestion::class;
    }

    public function getDomainObject(): string
    {
        return LiveChatQuestionDomainObject::class;
    }
}
