<?php

namespace HiEvents\Repository\Interfaces;

use HiEvents\DomainObjects\QuestionAnswerDomainObject;
use HiEvents\Services\Domain\Question\DTO\QuestionAnswerCountDTO;
use Illuminate\Support\Collection;

/**
 * @extends RepositoryInterface<QuestionAnswerDomainObject>
 */
interface QuestionAnswerRepositoryInterface extends RepositoryInterface
{
    /**
     * @param int[] $questionIds
     * @return Collection<int, QuestionAnswerCountDTO>
     */
    public function countActiveAnswerValues(int $eventId, array $questionIds): Collection;

    /**
     * @param int[] $questionIds
     * @return array<int, int> question_id => number of active answers
     */
    public function countActiveRespondents(int $eventId, array $questionIds): array;
}
