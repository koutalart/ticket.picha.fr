<?php

namespace HiEvents\Repository\Eloquent;

use Carbon\CarbonInterface;
use HiEvents\DomainObjects\QuestionAnswerFileDomainObject;
use HiEvents\Models\QuestionAnswerFile;
use HiEvents\Repository\Interfaces\QuestionAnswerFileRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * @extends BaseRepository<QuestionAnswerFileDomainObject>
 */
class QuestionAnswerFileRepository extends BaseRepository implements QuestionAnswerFileRepositoryInterface
{
    protected function getModel(): string
    {
        return QuestionAnswerFile::class;
    }

    public function getDomainObject(): string
    {
        return QuestionAnswerFileDomainObject::class;
    }

    public function findFilesToPurge(CarbonInterface $unattachedBefore): Collection
    {
        $models = QuestionAnswerFile::query()
            ->where(function (Builder $query) use ($unattachedBefore) {
                $query
                    ->where(function (Builder $query) use ($unattachedBefore) {
                        $query->whereNull('question_answer_id')
                            ->where('created_at', '<', $unattachedBefore->copy()->utc());
                    })
                    ->orWhereHas('order', fn (Builder $query) => $query->onlyTrashed())
                    ->orWhereHas('question_answer', fn (Builder $query) => $query->onlyTrashed());
            })
            ->limit(1000)
            ->get();

        return $this->handleResults($models);
    }

    public function forceDeleteByIds(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        return QuestionAnswerFile::withTrashed()
            ->whereIn('id', $ids)
            ->forceDelete();
    }
}
