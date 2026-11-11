<?php

declare(strict_types=1);

namespace HiEvents\Console\Commands\Assistant;

use Carbon\Carbon;
use HiEvents\Exceptions\AssistantActionRefusedException;
use HiEvents\Services\Application\Handlers\Sms\ScheduleSmsReminderHandler;
use HiEvents\Services\Domain\Assistant\DTO\AssistantScopeDTO;
use HiEvents\Services\Domain\Sms\DTO\SmsReminderRequestDTO;
use HiEvents\Services\Domain\Sms\Reminder\SmsReminderSchedulingService;

/**
 * Level 2. Example: php artisan anicka:campagne-sms --organisateur=4 --evenement=7 --message="Les portes ouvrent à 20h !" --envoi="2026-11-14 10:00"
 * Sent to the active attendees of the event with a valid phone (same audience as an SMS reminder of the dashboard).
 * Never at night: the sending time must be between 8:00 and 21:00, event time.
 */
class SmsCampaignAssistantCommand extends AbstractAssistantCommand
{
    private const DEFAULT_DELAY_MINUTES = 10;

    private const FIRST_HOUR = 8;

    private const LAST_HOUR = 21;

    protected $signature = 'anicka:campagne-sms
        {--evenement= : Event id (required)}
        {--message= : Text of the SMS (required)}
        {--envoi= : Sending time "Y-m-d H:i", event time zone (default: 10 minutes after the confirmation)}
        {--nom= : Name of the campaign}';

    protected $description = 'Assistant, level 2: send an SMS campaign to the attendees of an event';

    protected function level(): int
    {
        return 2;
    }

    protected function validateInput(): void
    {
        $this->requiredString('message');
        $this->localDateTime('envoi');
    }

    protected function describe(AssistantScopeDTO $scope): string
    {
        $sendAt = $this->localDateTime('envoi');

        if ($sendAt !== null) {
            $this->assertDaytime($sendAt, $scope);
        }

        $preview = $this->laravel->make(SmsReminderSchedulingService::class)->preview($this->request($scope, $sendAt));

        if ($preview->recipients === 0) {
            throw new AssistantActionRefusedException(__('No attendee of this event can receive this SMS.'));
        }

        return __('Send an SMS to :count attendees of ":event" (:sms SMS), :when: ":message"', [
            'count' => $preview->recipients,
            'event' => $scope->event->getTitle(),
            'sms' => $preview->smsCount,
            'when' => $sendAt ?? __('10 minutes after the confirmation'),
            'message' => $this->requiredString('message'),
        ]);
    }

    protected function perform(AssistantScopeDTO $scope): array
    {
        $sendAt = $this->localDateTime('envoi')
            ?? Carbon::now($scope->event->getTimezone())->addMinutes(self::DEFAULT_DELAY_MINUTES)->format('Y-m-d H:i');

        $this->assertDaytime($sendAt, $scope);

        $campaign = $this->laravel->make(ScheduleSmsReminderHandler::class)->handle($this->request($scope, $sendAt));

        return [
            'campagne_sms_id' => $campaign->getId(),
            'envoi_prevu' => $sendAt,
            'fuseau' => $scope->event->getTimezone(),
        ];
    }

    private function request(AssistantScopeDTO $scope, ?string $sendAt): SmsReminderRequestDTO
    {
        return new SmsReminderRequestDTO(
            organizerId: $scope->organizer->getId(),
            eventId: $scope->eventId(),
            message: $this->requiredString('message'),
            sendAt: $sendAt,
            name: $this->optionalString('nom'),
            userId: $this->accountOwnerUserId($scope),
        );
    }

    /**
     * @throws AssistantActionRefusedException
     */
    private function assertDaytime(string $sendAt, AssistantScopeDTO $scope): void
    {
        $hour = Carbon::createFromFormat('Y-m-d H:i', $sendAt, $scope->event->getTimezone())->hour;

        if ($hour < self::FIRST_HOUR || $hour >= self::LAST_HOUR) {
            throw new AssistantActionRefusedException(__('No SMS at night: choose a sending time between 8:00 and 21:00.'));
        }
    }
}
