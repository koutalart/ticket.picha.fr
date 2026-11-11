<?php

declare(strict_types=1);

namespace HiEvents\Console\Commands\Assistant;

use HiEvents\DomainObjects\Enums\MessageTypeEnum;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\Exceptions\AssistantActionRefusedException;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Application\Handlers\Message\DTO\SendMessageDTO;
use HiEvents\Services\Application\Handlers\Message\SendMessageHandler;
use HiEvents\Services\Domain\Assistant\DTO\AssistantScopeDTO;

/**
 * Level 2. Example: php artisan anicka:campagne-email --organisateur=4 --evenement=7 --sujet="Infos pratiques" --message="Bonjour, ..."
 * Goes through the messages of the dashboard (same checks, same sending queue). The text is plain text.
 */
class EmailCampaignAssistantCommand extends AbstractAssistantCommand
{
    private const TARGETS = [
        'tous' => MessageTypeEnum::ALL_ATTENDEES,
        'tarifs' => MessageTypeEnum::TICKET_HOLDERS,
        'acheteurs-tarifs' => MessageTypeEnum::ORDER_OWNERS_WITH_PRODUCT,
    ];

    protected $signature = 'anicka:campagne-email
        {--evenement= : Event id (required)}
        {--sujet= : Subject of the e-mail (required)}
        {--message= : Text of the e-mail, plain text, line breaks kept (required)}
        {--cible=tous : tous (every attendee), tarifs (attendees of --tarifs) or acheteurs-tarifs (buyers of --tarifs)}
        {--tarifs= : Product ids separated by commas, for --cible=tarifs or acheteurs-tarifs}
        {--envoi= : Sending time "Y-m-d H:i", event time zone (default: at once)}';

    protected $description = 'Assistant, level 2: send an e-mail to the attendees of an event';

    protected function level(): int
    {
        return 2;
    }

    protected function validateInput(): void
    {
        $this->requiredString('sujet');
        $this->requiredString('message');
        $this->localDateTime('envoi');

        if ($this->target() !== MessageTypeEnum::ALL_ATTENDEES && $this->idList('tarifs') === []) {
            throw new AssistantActionRefusedException(__('Give the products with --tarifs.'));
        }
    }

    protected function describe(AssistantScopeDTO $scope): string
    {
        $count = $this->recipientCount($scope);

        if ($count === 0) {
            throw new AssistantActionRefusedException(__('Nobody would receive this e-mail.'));
        }

        return __('Send the e-mail ":subject" to :count recipients of ":event", :when.', [
            'subject' => $this->requiredString('sujet'),
            'count' => $count,
            'event' => $scope->event->getTitle(),
            'when' => $this->localDateTime('envoi') ?? __('at once'),
        ]);
    }

    protected function perform(AssistantScopeDTO $scope): array
    {
        $message = $this->laravel->make(SendMessageHandler::class)->handle(new SendMessageDTO(
            account_id: $scope->accountId(),
            event_id: $scope->eventId(),
            subject: $this->requiredString('sujet'),
            message: $this->html($this->requiredString('message')),
            type: $this->target(),
            is_test: false,
            send_copy_to_current_user: false,
            sent_by_user_id: $this->accountOwnerUserId($scope),
            order_statuses: ['COMPLETED'],
            product_ids: $this->idList('tarifs'),
            scheduled_at: $this->localDateTime('envoi'),
        ));

        return [
            'message_id' => $message->getId(),
            'statut' => $message->getStatus(),
            'envoi_prevu' => $message->getScheduledAt(),
        ];
    }

    private function target(): MessageTypeEnum
    {
        return self::TARGETS[$this->choiceOption('cible', array_keys(self::TARGETS), 'tous')];
    }

    private function recipientCount(AssistantScopeDTO $scope): int
    {
        $attendees = $this->laravel->make(AttendeeRepositoryInterface::class);

        return match ($this->target()) {
            MessageTypeEnum::TICKET_HOLDERS => $attendees->countWhere([
                'event_id' => $scope->eventId(),
                'status' => AttendeeStatus::ACTIVE->name,
                ['product_id', 'in', $this->idList('tarifs')],
            ]),
            MessageTypeEnum::ORDER_OWNERS_WITH_PRODUCT => $this->laravel->make(OrderRepositoryInterface::class)
                ->countOrdersAssociatedWithProducts(
                    eventId: $scope->eventId(),
                    productIds: $this->idList('tarifs'),
                    orderStatuses: ['COMPLETED'],
                ),
            default => $attendees->countWhere([
                'event_id' => $scope->eventId(),
                'status' => AttendeeStatus::ACTIVE->name,
            ]),
        };
    }

    private function html(string $text): string
    {
        $paragraphs = preg_split('/\R{2,}/', trim($text)) ?: [];

        return implode('', array_map(
            fn (string $paragraph) => '<p>'.nl2br(htmlspecialchars(trim($paragraph), ENT_QUOTES | ENT_HTML5, 'UTF-8')).'</p>',
            $paragraphs,
        ));
    }
}
