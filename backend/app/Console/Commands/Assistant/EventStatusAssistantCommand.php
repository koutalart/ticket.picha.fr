<?php

declare(strict_types=1);

namespace HiEvents\Console\Commands\Assistant;

use HiEvents\DomainObjects\Status\EventStatus;
use HiEvents\Exceptions\AssistantActionRefusedException;
use HiEvents\Services\Application\Handlers\Event\DTO\UpdateEventStatusDTO;
use HiEvents\Services\Application\Handlers\Event\UpdateEventStatusHandler;
use HiEvents\Services\Domain\Assistant\DTO\AssistantScopeDTO;

/**
 * Level 2. Example: php artisan anicka:statut-evenement --organisateur=4 --evenement=7 --statut=publie
 */
class EventStatusAssistantCommand extends AbstractAssistantCommand
{
    private const STATUSES = [
        'publie' => EventStatus::LIVE,
        'brouillon' => EventStatus::DRAFT,
    ];

    protected $signature = 'anicka:statut-evenement
        {--evenement= : Event id (required)}
        {--statut= : publie (online) or brouillon (offline) (required)}';

    protected $description = 'Assistant, level 2: publish or unpublish an event';

    protected function level(): int
    {
        return 2;
    }

    protected function validateInput(): void
    {
        $this->status();
    }

    protected function describe(AssistantScopeDTO $scope): string
    {
        if ($scope->event->getStatus() === $this->status()->name) {
            throw new AssistantActionRefusedException(__('The event is already in this state.'));
        }

        return $this->status() === EventStatus::LIVE
            ? __('Publish ":event" (online, tickets on sale).', ['event' => $scope->event->getTitle()])
            : __('Unpublish ":event" (back to draft, no more sales).', ['event' => $scope->event->getTitle()]);
    }

    protected function perform(AssistantScopeDTO $scope): array
    {
        $event = $this->laravel->make(UpdateEventStatusHandler::class)->handle(new UpdateEventStatusDTO(
            status: $this->status()->name,
            eventId: $scope->eventId(),
            accountId: $scope->accountId(),
        ));

        return ['evenement_id' => $event->getId(), 'statut' => $event->getStatus()];
    }

    private function status(): EventStatus
    {
        return self::STATUSES[$this->choiceOption('statut', array_keys(self::STATUSES))];
    }
}
