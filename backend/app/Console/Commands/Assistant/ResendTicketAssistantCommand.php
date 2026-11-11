<?php

declare(strict_types=1);

namespace HiEvents\Console\Commands\Assistant;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\Exceptions\AssistantActionRefusedException;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Services\Application\Handlers\Attendee\DTO\ResendAttendeeTicketDTO;
use HiEvents\Services\Application\Handlers\Attendee\ResendAttendeeTicketHandler;
use HiEvents\Services\Domain\Assistant\DTO\AssistantScopeDTO;

/**
 * Level 1. Example: php artisan anicka:renvoyer-billet --organisateur=4 --evenement=7 --email=client@example.com
 */
class ResendTicketAssistantCommand extends AbstractAssistantCommand
{
    private const MAX_TICKETS = 20;

    protected $signature = 'anicka:renvoyer-billet
        {--evenement= : Event id (required)}
        {--participant= : Attendee id}
        {--email= : Or every active ticket of this e-mail address for the event}';

    protected $description = 'Assistant, level 1: send an attendee ticket again by e-mail';

    protected function level(): int
    {
        return 1;
    }

    protected function validateInput(): void
    {
        if (($this->optionalId('participant') === null) === ($this->optionalString('email') === null)) {
            throw new AssistantActionRefusedException(__('Give either --participant or --email.'));
        }
    }

    protected function describe(AssistantScopeDTO $scope): string
    {
        $attendees = $this->attendees($scope);

        return __('Send again :count ticket(s) of :name to :email.', [
            'count' => count($attendees),
            'name' => trim($attendees[0]->getFirstName().' '.$attendees[0]->getLastName()),
            'email' => $attendees[0]->getEmail(),
        ]);
    }

    protected function perform(AssistantScopeDTO $scope): array
    {
        $handler = $this->laravel->make(ResendAttendeeTicketHandler::class);
        $sent = [];

        foreach ($this->attendees($scope) as $attendee) {
            $handler->handle(new ResendAttendeeTicketDTO(attendeeId: $attendee->getId(), eventId: $scope->eventId()));
            $sent[] = $attendee->getId();
        }

        return ['participants' => $sent, 'email' => $this->attendees($scope)[0]->getEmail()];
    }

    /**
     * @return AttendeeDomainObject[]
     *
     * @throws AssistantActionRefusedException
     */
    private function attendees(AssistantScopeDTO $scope): array
    {
        $where = ['event_id' => $scope->eventId()];
        $attendeeId = $this->optionalId('participant');

        if ($attendeeId !== null) {
            $where['id'] = $attendeeId;
        } else {
            $where[] = ['email', 'ilike', (string) $this->optionalString('email')];
            $where['status'] = AttendeeStatus::ACTIVE->name;
        }

        $attendees = $this->laravel->make(AttendeeRepositoryInterface::class)->findWhere($where)->all();

        if ($attendees === []) {
            throw new AssistantActionRefusedException(__('No ticket found for this attendee in this event.'));
        }

        if (count($attendees) > self::MAX_TICKETS) {
            throw new AssistantActionRefusedException(__('Too many tickets for this address: give --participant.'));
        }

        return array_values($attendees);
    }
}
