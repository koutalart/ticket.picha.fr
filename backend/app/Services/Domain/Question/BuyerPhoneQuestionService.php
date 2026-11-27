<?php

namespace HiEvents\Services\Domain\Question;

use HiEvents\DomainObjects\Enums\QuestionBelongsTo;
use HiEvents\DomainObjects\Enums\QuestionTypeEnum;
use HiEvents\Repository\Interfaces\QuestionRepositoryInterface;

class BuyerPhoneQuestionService
{
    public function __construct(
        private readonly QuestionRepositoryInterface $questionRepository,
    ) {}

    public function ensureRequired(int $eventId): void
    {
        $existing = $this->questionRepository->findFirstWhere([
            'event_id' => $eventId,
            'type' => QuestionTypeEnum::PHONE->name,
            'belongs_to' => QuestionBelongsTo::ORDER->name,
        ]);

        if ($existing !== null) {
            if (! $existing->getRequired()) {
                $this->questionRepository->updateWhere(
                    attributes: ['required' => true],
                    where: ['id' => $existing->getId()],
                );
            }

            return;
        }

        $this->questionRepository->create([
            'event_id' => $eventId,
            'title' => __('Numéro de téléphone'),
            'required' => true,
            'type' => QuestionTypeEnum::PHONE->name,
            'belongs_to' => QuestionBelongsTo::ORDER->name,
            'order' => 1,
            'is_hidden' => false,
        ]);
    }
}
