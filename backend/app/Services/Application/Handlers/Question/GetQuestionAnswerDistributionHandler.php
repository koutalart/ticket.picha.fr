<?php

namespace HiEvents\Services\Application\Handlers\Question;

use HiEvents\DomainObjects\QuestionDomainObject;
use HiEvents\Repository\Interfaces\QuestionAnswerRepositoryInterface;
use HiEvents\Repository\Interfaces\QuestionRepositoryInterface;
use HiEvents\Services\Application\Handlers\Question\DTO\QuestionAnswerDistributionDTO;
use HiEvents\Services\Application\Handlers\Question\DTO\QuestionAnswerDistributionOptionDTO;
use HiEvents\Services\Domain\Question\DTO\QuestionAnswerCountDTO;
use Illuminate\Support\Collection;

class GetQuestionAnswerDistributionHandler
{
    public function __construct(
        private readonly QuestionRepositoryInterface $questionRepository,
        private readonly QuestionAnswerRepositoryInterface $questionAnswerRepository,
    ) {}

    /**
     * @return Collection<int, QuestionAnswerDistributionDTO>
     */
    public function handle(int $eventId): Collection
    {
        $questions = $this->questionRepository
            ->findWhere([QuestionDomainObject::EVENT_ID => $eventId])
            ->filter(fn (QuestionDomainObject $question) => $question->isPreDefinedChoice())
            ->sortBy(fn (QuestionDomainObject $question) => [$question->getOrder() ?? PHP_INT_MAX, $question->getId()])
            ->values();

        if ($questions->isEmpty()) {
            return collect();
        }

        $questionIds = $questions->map(fn (QuestionDomainObject $question) => $question->getId())->all();
        $counts = $this->questionAnswerRepository
            ->countActiveAnswerValues($eventId, $questionIds)
            ->groupBy(fn (QuestionAnswerCountDTO $count) => $count->questionId);
        $respondents = $this->questionAnswerRepository->countActiveRespondents($eventId, $questionIds);

        return $questions->map(fn (QuestionDomainObject $question) => $this->buildDistribution(
            question: $question,
            counts: $counts->get($question->getId(), collect()),
            totalResponses: $respondents[$question->getId()] ?? 0,
        ));
    }

    private function buildDistribution(
        QuestionDomainObject $question,
        Collection $counts,
        int $totalResponses,
    ): QuestionAnswerDistributionDTO {
        $countsByValue = $counts->mapWithKeys(fn (QuestionAnswerCountDTO $count) => [$count->value => $count->count]);
        $options = $question->getOptions();
        if (is_string($options)) {
            $options = json_decode($options, true);
        }
        $definedOptions = array_values(array_filter(is_array($options) ? $options : [], 'is_string'));

        $labels = collect($definedOptions)
            ->merge($countsByValue->keys())
            ->unique()
            ->values();

        $distribution = $labels
            ->map(fn (string $label, int $position) => [
                'label' => $label,
                'count' => (int) $countsByValue->get($label, 0),
                'position' => $position,
            ])
            ->sort(fn (array $a, array $b) => [$b['count'], $a['position']] <=> [$a['count'], $b['position']])
            ->map(fn (array $option) => new QuestionAnswerDistributionOptionDTO(
                label: $option['label'],
                count: $option['count'],
                percentage: $totalResponses > 0 ? round($option['count'] / $totalResponses * 100, 1) : 0.0,
            ))
            ->values()
            ->all();

        return new QuestionAnswerDistributionDTO(
            question_id: $question->getId(),
            title: $question->getTitle(),
            type: $question->getType(),
            belongs_to: $question->getBelongsTo(),
            total_responses: $totalResponses,
            options: $distribution,
        );
    }
}
