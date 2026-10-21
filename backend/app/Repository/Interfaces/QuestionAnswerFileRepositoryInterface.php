<?php

namespace HiEvents\Repository\Interfaces;

use Carbon\CarbonInterface;
use HiEvents\DomainObjects\QuestionAnswerFileDomainObject;
use Illuminate\Support\Collection;

/**
 * @extends RepositoryInterface<QuestionAnswerFileDomainObject>
 */
interface QuestionAnswerFileRepositoryInterface extends RepositoryInterface
{
    /**
     * Files never attached to an answer before the given date, or whose order or answer has been deleted.
     *
     * @return Collection<QuestionAnswerFileDomainObject>
     */
    public function findFilesToPurge(CarbonInterface $unattachedBefore): Collection;

    public function forceDeleteByIds(array $ids): int;
}
