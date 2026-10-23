<?php

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\QuestionAnswerDomainObject;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Models\QuestionAnswer;
use HiEvents\Repository\Interfaces\QuestionAnswerRepositoryInterface;
use HiEvents\Services\Domain\Question\DTO\QuestionAnswerCountDTO;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * @extends BaseRepository<QuestionAnswerDomainObject>
 */
class QuestionAnswerRepository extends BaseRepository implements QuestionAnswerRepositoryInterface
{
    private const ANSWER_VALUES_SQL = "jsonb_array_elements_text(CASE WHEN jsonb_typeof(question_answers.answer) = 'array' THEN question_answers.answer ELSE jsonb_build_array(question_answers.answer) END) AS answer_values(value)";

    protected function getModel(): string
    {
        return QuestionAnswer::class;
    }

    public function getDomainObject(): string
    {
        return QuestionAnswerDomainObject::class;
    }

    public function countActiveAnswerValues(int $eventId, array $questionIds): Collection
    {
        if ($questionIds === []) {
            return collect();
        }

        return $this->activeAnswersQuery($eventId, $questionIds)
            ->crossJoin(DB::raw(self::ANSWER_VALUES_SQL))
            ->whereNotNull('answer_values.value')
            ->where('answer_values.value', '<>', '')
            ->groupBy('question_answers.question_id', 'answer_values.value')
            ->select([
                'question_answers.question_id',
                'answer_values.value',
                DB::raw('COUNT(*) AS answer_count'),
            ])
            ->get()
            ->map(fn (object $row) => new QuestionAnswerCountDTO(
                questionId: (int) $row->question_id,
                value: (string) $row->value,
                count: (int) $row->answer_count,
            ));
    }

    public function countActiveRespondents(int $eventId, array $questionIds): array
    {
        if ($questionIds === []) {
            return [];
        }

        return $this->activeAnswersQuery($eventId, $questionIds)
            ->whereNotNull('question_answers.answer')
            ->whereRaw("question_answers.answer NOT IN ('\"\"'::jsonb, '[]'::jsonb, 'null'::jsonb)")
            ->groupBy('question_answers.question_id')
            ->select([
                'question_answers.question_id',
                DB::raw('COUNT(*) AS respondent_count'),
            ])
            ->pluck('respondent_count', 'question_id')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    private function activeAnswersQuery(int $eventId, array $questionIds): Builder
    {
        return DB::table('question_answers')
            ->join('orders', 'orders.id', '=', 'question_answers.order_id')
            ->leftJoin('attendees', 'attendees.id', '=', 'question_answers.attendee_id')
            ->whereIn('question_answers.question_id', $questionIds)
            ->whereNull('question_answers.deleted_at')
            ->where('orders.event_id', $eventId)
            ->whereNull('orders.deleted_at')
            ->whereIn('orders.status', [
                OrderStatus::COMPLETED->name,
                OrderStatus::AWAITING_OFFLINE_PAYMENT->name,
            ])
            ->where(function (Builder $query) {
                $query->whereNull('question_answers.attendee_id')
                    ->orWhere(function (Builder $attendeeQuery) {
                        $attendeeQuery->whereNull('attendees.deleted_at')
                            ->where('attendees.status', '<>', AttendeeStatus::CANCELLED->name);
                    });
            });
    }
}
