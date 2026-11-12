<?php

declare(strict_types=1);

namespace HiEvents\Console\Commands\Assistant;

use HiEvents\DomainObjects\Enums\QuestionBelongsTo;
use HiEvents\DomainObjects\Enums\QuestionTypeEnum;
use HiEvents\Exceptions\AssistantActionRefusedException;
use HiEvents\Services\Application\Handlers\Question\CreateQuestionHandler;
use HiEvents\Services\Application\Handlers\Question\DTO\UpsertQuestionDTO;
use HiEvents\Services\Domain\Assistant\DTO\AssistantScopeDTO;

/**
 * Level 2. A question asked once per order in the registration form.
 * Example: php artisan anicka:ajouter-question --organisateur=18 --evenement=25 --libelle="Entreprise" --type=texte --obligatoire=oui
 */
class CreateQuestionAssistantCommand extends AbstractAssistantCommand
{
    private const TYPES = [
        'texte' => QuestionTypeEnum::SINGLE_LINE_TEXT,
        'texte-long' => QuestionTypeEnum::MULTI_LINE_TEXT,
        'choix' => QuestionTypeEnum::RADIO,
        'choix-multiple' => QuestionTypeEnum::CHECKBOX,
        'telephone' => QuestionTypeEnum::PHONE,
        'date' => QuestionTypeEnum::DATE,
    ];

    private const CHOICE_TYPES = ['choix', 'choix-multiple'];

    protected $signature = 'anicka:ajouter-question
        {--evenement= : Event id (required)}
        {--libelle= : The question as shown in the form (required)}
        {--type=texte : texte, texte-long, choix, choix-multiple, telephone or date}
        {--options= : Answers to choose from, separated by | (required for choix and choix-multiple)}
        {--obligatoire=oui : oui or non}';

    protected $description = 'Assistant, level 2: add a question to the registration form of an event';

    protected function level(): int
    {
        return 2;
    }

    protected function validateInput(): void
    {
        if (mb_strlen($this->requiredString('libelle')) > 255) {
            throw new AssistantActionRefusedException(__('The question must have at most 255 characters.'));
        }

        $type = $this->choiceOption('type', array_keys(self::TYPES), 'texte');
        $options = $this->answers();

        if (in_array($type, self::CHOICE_TYPES, true) && count($options) < 2) {
            throw new AssistantActionRefusedException(__('A choice question needs at least two answers, separated by |.'));
        }

        if (! in_array($type, self::CHOICE_TYPES, true) && $options !== []) {
            throw new AssistantActionRefusedException(__('Answers to choose from are only for choix and choix-multiple.'));
        }

        $this->yesNo('obligatoire', true);
    }

    protected function describe(AssistantScopeDTO $scope): string
    {
        $options = $this->answers();

        return __('Add the :required question ":question":answers to the form of ":event".', [
            'required' => $this->yesNo('obligatoire', true) ? __('required') : __('optional'),
            'question' => $this->requiredString('libelle'),
            'answers' => $options === [] ? '' : ' ('.implode(', ', $options).')',
            'event' => $scope->event->getTitle(),
        ]);
    }

    protected function perform(AssistantScopeDTO $scope): array
    {
        $type = self::TYPES[$this->choiceOption('type', array_keys(self::TYPES), 'texte')];

        $question = $this->laravel->make(CreateQuestionHandler::class)->handle(new UpsertQuestionDTO(
            title: $this->requiredString('libelle'),
            type: $type,
            required: $this->yesNo('obligatoire', true),
            options: $this->answers() === [] ? null : $this->answers(),
            event_id: $scope->eventId(),
            product_ids: [],
            is_hidden: false,
            belongs_to: QuestionBelongsTo::ORDER,
        ));

        return [
            'question' => $question->getId(),
            'type' => $type->name,
        ];
    }

    /**
     * @return string[]
     */
    private function answers(): array
    {
        $value = $this->optionalString('options');

        if ($value === null) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map('trim', explode('|', $value)),
            fn (string $answer) => $answer !== '',
        )));
    }
}
