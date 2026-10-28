<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms\Reminder;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\Enums\SmsCampaignType;
use HiEvents\DomainObjects\Enums\SmsFilePriority;
use HiEvents\DomainObjects\Enums\SmsMessageAuthorType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Generated\AttendeeDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\EventDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SmsCampaignDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SmsCampaignRecipientDomainObjectAbstract;
use HiEvents\DomainObjects\SmsCampaignDomainObject;
use HiEvents\DomainObjects\SmsCampaignRecipientDomainObject;
use HiEvents\DomainObjects\Status\SmsCampaignRecipientStatus;
use HiEvents\DomainObjects\Status\SmsCampaignStatus;
use HiEvents\DomainObjects\Status\SmsMessageStatus;
use HiEvents\Exceptions\InvalidSmsPhoneNumberException;
use HiEvents\Exceptions\SmsFileRejectedException;
use HiEvents\Exceptions\SmsFileUnavailableException;
use HiEvents\Exceptions\SmsGatewayException;
use HiEvents\Exceptions\SmsGatewayNotConfiguredException;
use HiEvents\Exceptions\SmsRecipientOptedOutException;
use HiEvents\Repository\Eloquent\Value\OrderAndDirection;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsCampaignRecipientRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsCampaignRepositoryInterface;
use HiEvents\Services\Domain\Sms\DTO\PreparedSmsDTO;
use HiEvents\Services\Domain\Sms\DTO\SendSmsDTO;
use HiEvents\Services\Domain\Sms\DTO\SmsReminderDispatchResultDTO;
use HiEvents\Services\Domain\Sms\DTO\SmsReminderRecipientDTO;
use HiEvents\Services\Domain\Sms\SmsFileAlertService;
use HiEvents\Services\Domain\Sms\SmsFileStatusService;
use HiEvents\Services\Domain\Sms\SmsSendService;
use HiEvents\Services\Infrastructure\SmsFile\DTO\SmsFileMessageDTO;
use HiEvents\Services\Infrastructure\SmsFile\SmsFileClient;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sends the scheduled reminders that are due, a limited number of SMS per run.
 * Each recipient is claimed (PENDING to SCHEDULED) before the call to PICHA SMS, so a number is never sent the same reminder twice.
 * When the central SMS queue is enabled, the due SMS are handed to it in one request (stable ref per recipient) and the
 * queue paces them: no pause here.
 */
class SmsReminderDispatchService
{
    public function __construct(
        private readonly SmsCampaignRepositoryInterface $campaignRepository,
        private readonly SmsCampaignRecipientRepositoryInterface $recipientRepository,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly AttendeeRepositoryInterface $attendeeRepository,
        private readonly SmsReminderAudienceService $audienceService,
        private readonly SmsReminderSchedulingService $schedulingService,
        private readonly SmsReminderMessageRenderer $renderer,
        private readonly SmsReminderPersonalLinkProviderInterface $linkProvider,
        private readonly SmsSendService $sendService,
        private readonly ConfigRepository $config,
        private readonly LoggerInterface $logger,
        private readonly SmsFileClient $fileClient,
        private readonly SmsFileStatusService $fileStatusService,
        private readonly SmsFileAlertService $fileAlertService,
    ) {}

    public function dispatchDue(): SmsReminderDispatchResultDTO
    {
        $started = 0;
        foreach ($this->dueReminders() as $campaign) {
            if ($this->claimCampaign($campaign)) {
                $this->refreshRecipients($campaign);
                $started++;
            }
        }

        $throughFile = $this->fileClient->isEnabled();
        $budget = $throughFile
            ? SmsFileClient::MAX_PER_REQUEST
            : max(1, (int) $this->config->get('services.httpsms.reminder_batch_size', 200));
        $sent = 0;
        $failed = 0;
        $completed = 0;

        foreach ($this->remindersBeingSent() as $campaign) {
            if ($budget <= 0) {
                break;
            }

            [$batchSent, $batchFailed, $queueDown] = $throughFile
                ? $this->queueBatch($campaign, $budget)
                : [...$this->sendBatch($campaign, $budget), false];
            $budget -= $batchSent + $batchFailed;
            $sent += $batchSent;
            $failed += $batchFailed;

            if ($queueDown) {
                break;
            }

            if ($this->completeIfDone($campaign)) {
                $completed++;
            }
        }

        return new SmsReminderDispatchResultDTO(
            startedReminders: $started,
            sent: $sent,
            failed: $failed,
            completedReminders: $completed,
        );
    }

    private function dueReminders(): iterable
    {
        return $this->campaignRepository->findWhere(
            where: [
                SmsCampaignDomainObjectAbstract::TYPE => SmsCampaignType::REMINDER->value,
                SmsCampaignDomainObjectAbstract::STATUS => SmsCampaignStatus::SCHEDULED->value,
                [SmsCampaignDomainObjectAbstract::SCHEDULED_AT, '<=', $this->now()],
            ],
            orderAndDirections: [new OrderAndDirection(SmsCampaignDomainObjectAbstract::SCHEDULED_AT, 'asc')],
        );
    }

    private function remindersBeingSent(): iterable
    {
        return $this->campaignRepository->findWhere(
            where: [
                SmsCampaignDomainObjectAbstract::TYPE => SmsCampaignType::REMINDER->value,
                SmsCampaignDomainObjectAbstract::STATUS => SmsCampaignStatus::PROCESSING->value,
            ],
            orderAndDirections: [new OrderAndDirection(SmsCampaignDomainObjectAbstract::SCHEDULED_AT, 'asc')],
        );
    }

    private function claimCampaign(SmsCampaignDomainObject $campaign): bool
    {
        return $this->campaignRepository->updateWhere(
            attributes: [
                SmsCampaignDomainObjectAbstract::STATUS => SmsCampaignStatus::PROCESSING->value,
                SmsCampaignDomainObjectAbstract::STARTED_AT => $this->now(),
            ],
            where: [
                SmsCampaignDomainObjectAbstract::ID => $campaign->getId(),
                SmsCampaignDomainObjectAbstract::STATUS => SmsCampaignStatus::SCHEDULED->value,
            ],
        ) === 1;
    }

    /**
     * Attendees who registered, cancelled or unsubscribed since the reminder was scheduled.
     */
    private function refreshRecipients(SmsCampaignDomainObject $campaign): void
    {
        if ($campaign->getEventId() === null) {
            return;
        }

        $audience = collect($this->audienceService->build($campaign->getOrganizerId(), $campaign->getEventId())->recipients)
            ->keyBy(fn (SmsReminderRecipientDTO $recipient) => $recipient->phone);

        $existing = $this->recipientRepository
            ->findWhere([SmsCampaignRecipientDomainObjectAbstract::SMS_CAMPAIGN_ID => $campaign->getId()])
            ->keyBy(fn (SmsCampaignRecipientDomainObject $recipient) => $recipient->getPhone());

        $staleIds = $existing
            ->filter(fn (SmsCampaignRecipientDomainObject $recipient) => $recipient->getStatus() === SmsCampaignRecipientStatus::PENDING->value
                && ! $audience->has($recipient->getPhone()))
            ->map(fn (SmsCampaignRecipientDomainObject $recipient) => $recipient->getId())
            ->values()
            ->all();

        if ($staleIds !== []) {
            $this->recipientRepository->deleteWhere([
                SmsCampaignRecipientDomainObjectAbstract::SMS_CAMPAIGN_ID => $campaign->getId(),
                SmsCampaignRecipientDomainObjectAbstract::STATUS => SmsCampaignRecipientStatus::PENDING->value,
                [SmsCampaignRecipientDomainObjectAbstract::ID, 'in', $staleIds],
            ]);
        }

        $newRecipients = $audience->reject(fn (SmsReminderRecipientDTO $recipient) => $existing->has($recipient->phone))->values();
        foreach ($newRecipients->chunk(500) as $chunk) {
            $this->recipientRepository->insert($chunk
                ->map(fn (SmsReminderRecipientDTO $recipient) => $this->schedulingService->recipientAttributes($campaign, $recipient))
                ->values()
                ->all());
        }
    }

    /**
     * @return array{0: int, 1: int} sent, failed
     */
    private function sendBatch(SmsCampaignDomainObject $campaign, int $limit): array
    {
        $event = $this->findEvent($campaign);
        if ($event === null) {
            return [0, 0];
        }

        $recipients = $this->pendingRecipients($campaign, $limit);

        if ($recipients->isEmpty()) {
            return [0, 0];
        }

        $firstNames = $this->firstNames($recipients->map(fn (SmsCampaignRecipientDomainObject $recipient) => $recipient->getAttendeeId())->filter()->all());
        $pauseMicroseconds = max(0, (int) $this->config->get('services.httpsms.reminder_pause_ms', 250)) * 1000;
        $sent = 0;
        $failed = 0;

        foreach ($recipients as $recipient) {
            if (! $this->claimRecipient($recipient)) {
                continue;
            }

            if ($sent + $failed > 0 && $pauseMicroseconds > 0) {
                usleep($pauseMicroseconds);
            }

            $attendeeId = $recipient->getAttendeeId();
            $content = $this->renderer->render(
                (string) $campaign->getMessage(),
                $event,
                $attendeeId !== null ? ($firstNames[$attendeeId] ?? null) : null,
                $attendeeId !== null ? $this->linkProvider->findForAttendee($attendeeId) : null,
            );

            try {
                $message = $this->sendService->send(new SendSmsDTO(
                    organizerId: $campaign->getOrganizerId(),
                    phone: $recipient->getPhone(),
                    content: $content,
                    authorType: SmsMessageAuthorType::CAMPAIGN,
                    sentByUserId: $campaign->getCreatedByUserId(),
                ));

                if ($message->getProviderMessageId() !== null) {
                    $this->recipientRepository->updateFromArray($recipient->getId(), [
                        SmsCampaignRecipientDomainObjectAbstract::PROVIDER_MESSAGE_ID => $message->getProviderMessageId(),
                    ]);
                }

                $sent++;
            } catch (SmsGatewayNotConfiguredException $exception) {
                $this->markFailed($recipient, $exception->getMessage());
                $this->failCampaign($campaign, $exception->getMessage());

                return [$sent, $failed + 1];
            } catch (SmsRecipientOptedOutException|InvalidSmsPhoneNumberException|SmsGatewayException $exception) {
                $this->markFailed($recipient, $exception->getMessage());
                $failed++;
            } catch (Throwable $exception) {
                $this->logger->error('SMS reminder could not be sent', [
                    'sms_campaign_id' => $campaign->getId(),
                    'recipient_id' => $recipient->getId(),
                    'error' => $exception->getMessage(),
                ]);
                $this->markFailed($recipient, __('Unexpected error while sending'));
                $failed++;
            }
        }

        return [$sent, $failed];
    }

    /**
     * Hands the due SMS of a reminder to the central queue in one request, as a « campagne »: the queue paces them.
     * When the queue cannot be reached nothing is sent straight to PICHA SMS (it would flood the phone): the
     * recipients are put back to be retried on the next run and the supervision is told.
     *
     * @return array{0: int, 1: int, 2: bool} queued, failed, queue unavailable
     */
    private function queueBatch(SmsCampaignDomainObject $campaign, int $limit): array
    {
        $event = $this->findEvent($campaign);
        if ($event === null) {
            return [0, 0, false];
        }

        $recipients = $this->pendingRecipients($campaign, $limit);
        if ($recipients->isEmpty()) {
            return [0, 0, false];
        }

        $firstNames = $this->firstNames($recipients->map(fn (SmsCampaignRecipientDomainObject $recipient) => $recipient->getAttendeeId())->filter()->all());
        $failed = 0;
        /** @var array<int, array{0: SmsCampaignRecipientDomainObject, 1: PreparedSmsDTO}> $batch */
        $batch = [];
        $items = [];

        foreach ($recipients as $recipient) {
            if (! $this->claimRecipient($recipient)) {
                continue;
            }

            $attendeeId = $recipient->getAttendeeId();

            try {
                $prepared = $this->sendService->prepare(new SendSmsDTO(
                    organizerId: $campaign->getOrganizerId(),
                    phone: $recipient->getPhone(),
                    content: $this->renderer->render(
                        (string) $campaign->getMessage(),
                        $event,
                        $attendeeId !== null ? ($firstNames[$attendeeId] ?? null) : null,
                        $attendeeId !== null ? $this->linkProvider->findForAttendee($attendeeId) : null,
                    ),
                    authorType: SmsMessageAuthorType::CAMPAIGN,
                    sentByUserId: $campaign->getCreatedByUserId(),
                ));
            } catch (SmsGatewayNotConfiguredException $exception) {
                foreach ([...array_column($batch, 0), $recipient] as $claimed) {
                    $this->markFailed($claimed, $exception->getMessage());
                }
                $this->failCampaign($campaign, $exception->getMessage());

                return [0, $failed + count($batch) + 1, false];
            } catch (SmsRecipientOptedOutException|InvalidSmsPhoneNumberException $exception) {
                $this->markFailed($recipient, $exception->getMessage());
                $failed++;

                continue;
            }

            $batch[] = [$recipient, $prepared];
            $items[] = new SmsFileMessageDTO(
                organizerId: $campaign->getOrganizerId(),
                from: (string) $prepared->line->getFromNumber(),
                to: $prepared->phone,
                text: $prepared->sms->content,
                priority: SmsFilePriority::CAMPAIGN,
                ref: self::queueRef($campaign, $recipient),
                campaign: self::queueCampaign($campaign),
            );
        }

        if ($items === []) {
            return [0, $failed, false];
        }

        try {
            $results = $this->fileClient->enqueueMany($items);
        } catch (SmsFileUnavailableException $exception) {
            foreach ($batch as [$recipient]) {
                $this->releaseRecipient($recipient, __('The SMS queue cannot be reached, new attempt at the next run'));
            }
            $this->fileAlertService->campaignNotQueued($campaign->getOrganizerId(), self::queueCampaign($campaign), count($batch), $exception->getMessage(), retried: true);

            return [0, $failed, true];
        } catch (SmsFileRejectedException $exception) {
            foreach ($batch as [$recipient]) {
                $this->markFailed($recipient, $exception->getMessage());
            }
            $this->fileAlertService->campaignNotQueued($campaign->getOrganizerId(), self::queueCampaign($campaign), count($batch), $exception->getMessage(), retried: false);

            return [0, $failed + count($batch), false];
        }

        $queued = 0;
        foreach ($batch as $index => [$recipient, $prepared]) {
            $result = $results[$index];

            try {
                $message = $this->sendService->record($prepared, SmsMessageStatus::QUEUED);
                $this->fileStatusService->applyToMessage($message, $result);
                $this->fileStatusService->applyToRecipient($recipient, $result);
                $queued++;
            } catch (Throwable $exception) {
                $this->logger->error('SMS reminder queued but not recorded', [
                    'sms_campaign_id' => $campaign->getId(),
                    'recipient_id' => $recipient->getId(),
                    'queue_message_id' => $result->id,
                    'error' => $exception->getMessage(),
                ]);
                $failed++;
            }
        }

        return [$queued, $failed, false];
    }

    public static function queueCampaign(SmsCampaignDomainObject $campaign): string
    {
        return 'rappel-'.$campaign->getId();
    }

    public static function queueRef(SmsCampaignDomainObject $campaign, SmsCampaignRecipientDomainObject $recipient): string
    {
        return $campaign->getId().':'.$recipient->getId();
    }

    private function findEvent(SmsCampaignDomainObject $campaign): ?EventDomainObject
    {
        /** @var EventDomainObject|null $event */
        $event = $campaign->getEventId() !== null
            ? $this->eventRepository->findFirstWhere([EventDomainObjectAbstract::ID => $campaign->getEventId()])
            : null;

        if ($event === null) {
            $this->failCampaign($campaign, __('The event of this reminder no longer exists'));
        }

        return $event;
    }

    private function pendingRecipients(SmsCampaignDomainObject $campaign, int $limit): Collection
    {
        return $this->recipientRepository->findWhere(
            where: [
                SmsCampaignRecipientDomainObjectAbstract::SMS_CAMPAIGN_ID => $campaign->getId(),
                SmsCampaignRecipientDomainObjectAbstract::STATUS => SmsCampaignRecipientStatus::PENDING->value,
            ],
            orderAndDirections: [new OrderAndDirection(SmsCampaignRecipientDomainObjectAbstract::ID, 'asc')],
        )->take($limit)->values();
    }

    private function releaseRecipient(SmsCampaignRecipientDomainObject $recipient, string $reason): void
    {
        $this->recipientRepository->updateWhere(
            attributes: [
                SmsCampaignRecipientDomainObjectAbstract::STATUS => SmsCampaignRecipientStatus::PENDING->value,
                SmsCampaignRecipientDomainObjectAbstract::FAILURE_REASON => mb_substr($reason, 0, 500),
            ],
            where: [
                SmsCampaignRecipientDomainObjectAbstract::ID => $recipient->getId(),
                SmsCampaignRecipientDomainObjectAbstract::STATUS => SmsCampaignRecipientStatus::SCHEDULED->value,
            ],
        );
    }

    private function claimRecipient(SmsCampaignRecipientDomainObject $recipient): bool
    {
        return $this->recipientRepository->updateWhere(
            attributes: [SmsCampaignRecipientDomainObjectAbstract::STATUS => SmsCampaignRecipientStatus::SCHEDULED->value],
            where: [
                SmsCampaignRecipientDomainObjectAbstract::ID => $recipient->getId(),
                SmsCampaignRecipientDomainObjectAbstract::STATUS => SmsCampaignRecipientStatus::PENDING->value,
            ],
        ) === 1;
    }

    private function markFailed(SmsCampaignRecipientDomainObject $recipient, string $reason): void
    {
        $this->recipientRepository->updateFromArray($recipient->getId(), [
            SmsCampaignRecipientDomainObjectAbstract::STATUS => SmsCampaignRecipientStatus::FAILED->value,
            SmsCampaignRecipientDomainObjectAbstract::FAILURE_REASON => mb_substr($reason, 0, 500),
        ]);
    }

    private function failCampaign(SmsCampaignDomainObject $campaign, string $reason): void
    {
        $reason = mb_substr($reason, 0, 500);

        $this->recipientRepository->updateWhere(
            attributes: [
                SmsCampaignRecipientDomainObjectAbstract::STATUS => SmsCampaignRecipientStatus::FAILED->value,
                SmsCampaignRecipientDomainObjectAbstract::FAILURE_REASON => $reason,
            ],
            where: [
                SmsCampaignRecipientDomainObjectAbstract::SMS_CAMPAIGN_ID => $campaign->getId(),
                SmsCampaignRecipientDomainObjectAbstract::STATUS => SmsCampaignRecipientStatus::PENDING->value,
            ],
        );

        $this->campaignRepository->updateWhere(
            attributes: [
                SmsCampaignDomainObjectAbstract::STATUS => SmsCampaignStatus::FAILED->value,
                SmsCampaignDomainObjectAbstract::FAILURE_REASON => $reason,
                SmsCampaignDomainObjectAbstract::COMPLETED_AT => $this->now(),
            ],
            where: [
                SmsCampaignDomainObjectAbstract::ID => $campaign->getId(),
                SmsCampaignDomainObjectAbstract::STATUS => SmsCampaignStatus::PROCESSING->value,
            ],
        );
    }

    private function completeIfDone(SmsCampaignDomainObject $campaign): bool
    {
        $campaignWhere = [SmsCampaignRecipientDomainObjectAbstract::SMS_CAMPAIGN_ID => $campaign->getId()];

        if ($this->recipientRepository->countWhere([
            ...$campaignWhere,
            SmsCampaignRecipientDomainObjectAbstract::STATUS => SmsCampaignRecipientStatus::PENDING->value,
        ]) > 0) {
            return false;
        }

        $total = $this->recipientRepository->countWhere($campaignWhere);
        $failed = $this->recipientRepository->countWhere([
            ...$campaignWhere,
            [SmsCampaignRecipientDomainObjectAbstract::STATUS, 'in', [SmsCampaignRecipientStatus::FAILED->value, SmsCampaignRecipientStatus::EXPIRED->value]],
        ]);

        $allFailed = $total > 0 && $failed === $total;

        return $this->campaignRepository->updateWhere(
            attributes: [
                SmsCampaignDomainObjectAbstract::STATUS => $allFailed ? SmsCampaignStatus::FAILED->value : SmsCampaignStatus::SENT->value,
                SmsCampaignDomainObjectAbstract::FAILURE_REASON => $allFailed ? __('No SMS of this reminder could be sent') : null,
                SmsCampaignDomainObjectAbstract::COMPLETED_AT => $this->now(),
            ],
            where: [
                SmsCampaignDomainObjectAbstract::ID => $campaign->getId(),
                SmsCampaignDomainObjectAbstract::STATUS => SmsCampaignStatus::PROCESSING->value,
            ],
        ) === 1;
    }

    /**
     * @return array<int, string|null>
     */
    private function firstNames(array $attendeeIds): array
    {
        if ($attendeeIds === []) {
            return [];
        }

        return $this->attendeeRepository
            ->findWhereIn(AttendeeDomainObjectAbstract::ID, array_values(array_unique($attendeeIds)))
            ->mapWithKeys(fn (AttendeeDomainObject $attendee) => [$attendee->getId() => $attendee->getFirstName()])
            ->all();
    }

    private function now(): string
    {
        return Carbon::now('UTC')->toDateTimeString();
    }
}
