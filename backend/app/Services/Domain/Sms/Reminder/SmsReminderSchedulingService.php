<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms\Reminder;

use HiEvents\DomainObjects\Enums\SmsCampaignType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Generated\EventDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\OrganizerDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SmsCampaignDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SmsCampaignRecipientDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\SmsCampaignDomainObject;
use HiEvents\DomainObjects\Status\SmsCampaignRecipientStatus;
use HiEvents\DomainObjects\Status\SmsCampaignStatus;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Exceptions\SmsReminderNotCancellableException;
use HiEvents\Exceptions\SmsReminderNotEditableException;
use HiEvents\Exceptions\SmsReminderValidationException;
use HiEvents\Helper\SmsSegmentCounter;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsCampaignRecipientRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsCampaignRepositoryInterface;
use HiEvents\Services\Domain\Sms\DTO\SmsReminderAudienceDTO;
use HiEvents\Services\Domain\Sms\DTO\SmsReminderPreviewDTO;
use HiEvents\Services\Domain\Sms\DTO\SmsReminderRecipientDTO;
use HiEvents\Services\Domain\Sms\DTO\SmsReminderRequestDTO;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use Throwable;

class SmsReminderSchedulingService
{
    public const SEND_AT_FORMAT = 'Y-m-d H:i';

    public function __construct(
        private readonly OrganizerRepositoryInterface $organizerRepository,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly SmsCampaignRepositoryInterface $campaignRepository,
        private readonly SmsCampaignRecipientRepositoryInterface $recipientRepository,
        private readonly SmsReminderAudienceService $audienceService,
        private readonly SmsReminderMessageRenderer $renderer,
        private readonly SmsReminderPersonalLinkProviderInterface $linkProvider,
        private readonly DatabaseManager $databaseManager,
    ) {}

    /**
     * @throws ResourceNotFoundException
     * @throws SmsReminderValidationException
     */
    public function preview(SmsReminderRequestDTO $dto): SmsReminderPreviewDTO
    {
        $event = $this->findEvent($dto->organizerId, $dto->eventId);
        $audience = $this->audienceService->build($dto->organizerId, $dto->eventId);
        $scheduledAt = $dto->sendAt !== null ? $this->toUtc($dto->sendAt, $event) : null;

        return $this->buildPreview($dto->message, $event, $audience, $scheduledAt);
    }

    /**
     * @throws ResourceNotFoundException
     * @throws SmsReminderValidationException
     * @throws Throwable
     */
    public function schedule(SmsReminderRequestDTO $dto): SmsCampaignDomainObject
    {
        $event = $this->findEvent($dto->organizerId, $dto->eventId);

        /** @var OrganizerDomainObject|null $organizer */
        $organizer = $this->organizerRepository->findFirstWhere([OrganizerDomainObjectAbstract::ID => $dto->organizerId]);
        if ($organizer === null) {
            throw new ResourceNotFoundException(__('Organizer not found'));
        }

        if (trim($dto->message) === '') {
            throw new SmsReminderValidationException(__('The reminder message cannot be empty'));
        }

        if ($dto->sendAt === null) {
            throw new SmsReminderValidationException(__('Choose the date and time of sending'), 'send_at');
        }

        $scheduledAt = $this->toUtc($dto->sendAt, $event);
        if (Carbon::parse($scheduledAt, 'UTC')->lte(Carbon::now('UTC'))) {
            throw new SmsReminderValidationException(__('The sending date must be in the future'), 'send_at');
        }

        $audience = $this->audienceService->build($dto->organizerId, $dto->eventId);

        return $this->databaseManager->transaction(function () use ($dto, $event, $organizer, $audience, $scheduledAt) {
            /** @var SmsCampaignDomainObject $campaign */
            $campaign = $this->campaignRepository->create([
                SmsCampaignDomainObjectAbstract::ACCOUNT_ID => $organizer->getAccountId(),
                SmsCampaignDomainObjectAbstract::ORGANIZER_ID => $dto->organizerId,
                SmsCampaignDomainObjectAbstract::EVENT_ID => $event->getId(),
                SmsCampaignDomainObjectAbstract::NAME => $this->name($dto, $event, $scheduledAt),
                SmsCampaignDomainObjectAbstract::MESSAGE => trim($dto->message),
                SmsCampaignDomainObjectAbstract::TYPE => SmsCampaignType::REMINDER->value,
                SmsCampaignDomainObjectAbstract::STATUS => SmsCampaignStatus::SCHEDULED->value,
                SmsCampaignDomainObjectAbstract::SCHEDULED_AT => $scheduledAt,
                SmsCampaignDomainObjectAbstract::CREATED_BY_USER_ID => $dto->userId,
            ]);

            foreach (array_chunk($audience->recipients, 500) as $chunk) {
                $this->recipientRepository->insert(array_map(
                    fn (SmsReminderRecipientDTO $recipient) => $this->recipientAttributes($campaign, $recipient),
                    $chunk,
                ));
            }

            return $campaign;
        });
    }

    /**
     * @throws ResourceNotFoundException
     * @throws SmsReminderNotCancellableException
     */
    public function cancel(int $organizerId, int $campaignId): void
    {
        $where = [
            SmsCampaignDomainObjectAbstract::ID => $campaignId,
            SmsCampaignDomainObjectAbstract::ORGANIZER_ID => $organizerId,
            SmsCampaignDomainObjectAbstract::TYPE => SmsCampaignType::REMINDER->value,
        ];

        if ($this->campaignRepository->findFirstWhere($where) === null) {
            throw new ResourceNotFoundException(__('Reminder not found'));
        }

        $cancelled = $this->campaignRepository->updateWhere(
            attributes: [
                SmsCampaignDomainObjectAbstract::STATUS => SmsCampaignStatus::CANCELLED->value,
                SmsCampaignDomainObjectAbstract::COMPLETED_AT => Carbon::now('UTC')->toDateTimeString(),
            ],
            where: [...$where, SmsCampaignDomainObjectAbstract::STATUS => SmsCampaignStatus::SCHEDULED->value],
        );

        if ($cancelled === 0) {
            throw new SmsReminderNotCancellableException(__('This reminder is already being sent and can no longer be cancelled'));
        }
    }

    /**
     * Changes the text, name or time of a reminder not sent yet. An automatic reminder edited this way no longer follows the event date.
     *
     * @throws ResourceNotFoundException
     * @throws SmsReminderValidationException
     * @throws SmsReminderNotEditableException
     */
    public function update(int $campaignId, SmsReminderRequestDTO $dto): SmsCampaignDomainObject
    {
        $where = [
            SmsCampaignDomainObjectAbstract::ID => $campaignId,
            SmsCampaignDomainObjectAbstract::ORGANIZER_ID => $dto->organizerId,
            SmsCampaignDomainObjectAbstract::TYPE => SmsCampaignType::REMINDER->value,
        ];

        /** @var SmsCampaignDomainObject|null $reminder */
        $reminder = $this->campaignRepository->findFirstWhere($where);
        if ($reminder === null || $reminder->getEventId() === null) {
            throw new ResourceNotFoundException(__('Reminder not found'));
        }

        if ($reminder->getStatus() !== SmsCampaignStatus::SCHEDULED->value) {
            throw new SmsReminderNotEditableException(__('This reminder is already being sent and can no longer be changed'));
        }

        $event = $this->findEvent($dto->organizerId, $reminder->getEventId());

        if (trim($dto->message) === '') {
            throw new SmsReminderValidationException(__('The reminder message cannot be empty'));
        }

        if ($dto->sendAt === null) {
            throw new SmsReminderValidationException(__('Choose the date and time of sending'), 'send_at');
        }

        $scheduledAt = $this->toUtc($dto->sendAt, $event);
        if (Carbon::parse($scheduledAt, 'UTC')->lte(Carbon::now('UTC'))) {
            throw new SmsReminderValidationException(__('The sending date must be in the future'), 'send_at');
        }

        $name = trim((string) $dto->name);

        return $this->databaseManager->transaction(function () use ($reminder, $where, $dto, $name, $scheduledAt, $campaignId) {
            $updated = $this->campaignRepository->updateWhere(
                attributes: [
                    SmsCampaignDomainObjectAbstract::MESSAGE => trim($dto->message),
                    SmsCampaignDomainObjectAbstract::NAME => $name !== '' ? mb_substr($name, 0, 120) : $reminder->getName(),
                    SmsCampaignDomainObjectAbstract::SCHEDULED_AT => $scheduledAt,
                    SmsCampaignDomainObjectAbstract::AUTO_EDITED => true,
                ],
                where: [...$where, SmsCampaignDomainObjectAbstract::STATUS => SmsCampaignStatus::SCHEDULED->value],
            );

            if ($updated === 0) {
                throw new SmsReminderNotEditableException(__('This reminder is already being sent and can no longer be changed'));
            }

            $this->recipientRepository->updateWhere(
                attributes: [SmsCampaignRecipientDomainObjectAbstract::SCHEDULED_AT => $scheduledAt],
                where: [
                    SmsCampaignRecipientDomainObjectAbstract::SMS_CAMPAIGN_ID => $campaignId,
                    SmsCampaignRecipientDomainObjectAbstract::STATUS => SmsCampaignRecipientStatus::PENDING->value,
                ],
            );

            return $this->campaignRepository->findFirstWhere($where);
        });
    }

    public function recipientAttributes(SmsCampaignDomainObject $campaign, SmsReminderRecipientDTO $recipient): array
    {
        $now = Carbon::now('UTC')->toDateTimeString();

        return [
            SmsCampaignRecipientDomainObjectAbstract::SMS_CAMPAIGN_ID => $campaign->getId(),
            SmsCampaignRecipientDomainObjectAbstract::PHONE => $recipient->phone,
            SmsCampaignRecipientDomainObjectAbstract::CONTACT_NAME => $recipient->contactName,
            SmsCampaignRecipientDomainObjectAbstract::ATTENDEE_ID => $recipient->attendeeId,
            SmsCampaignRecipientDomainObjectAbstract::STATUS => SmsCampaignRecipientStatus::PENDING->value,
            SmsCampaignRecipientDomainObjectAbstract::SCHEDULED_AT => $campaign->getScheduledAt(),
            SmsCampaignRecipientDomainObjectAbstract::CREATED_AT => $now,
            SmsCampaignRecipientDomainObjectAbstract::UPDATED_AT => $now,
        ];
    }

    private function buildPreview(string $message, EventDomainObject $event, SmsReminderAudienceDTO $audience, ?string $scheduledAt): SmsReminderPreviewDTO
    {
        $first = $audience->recipients[0] ?? null;
        $sampleLink = $first !== null ? $this->linkProvider->findForAttendee($first->attendeeId) : null;

        $smsCount = 0;
        foreach ($audience->recipients as $recipient) {
            $smsCount += SmsSegmentCounter::count($this->renderer->render($message, $event, $recipient->firstName, $sampleLink));
        }

        return new SmsReminderPreviewDTO(
            recipients: count($audience->recipients),
            smsCount: $smsCount,
            sampleMessage: $this->renderer->render($message, $event, $first?->firstName, $sampleLink),
            activeAttendees: $audience->activeAttendees,
            withoutValidPhone: $audience->withoutValidPhone,
            optedOut: $audience->optedOut,
            sharedPhone: $audience->sharedPhone,
            personalLinkAvailable: $sampleLink !== null,
            eventTimezone: $event->getTimezone() ?: 'UTC',
            scheduledAt: $scheduledAt,
        );
    }

    /**
     * @throws ResourceNotFoundException
     */
    private function findEvent(int $organizerId, int $eventId): EventDomainObject
    {
        /** @var EventDomainObject|null $event */
        $event = $this->eventRepository->findFirstWhere([
            EventDomainObjectAbstract::ID => $eventId,
            EventDomainObjectAbstract::ORGANIZER_ID => $organizerId,
        ]);

        if ($event === null) {
            throw new ResourceNotFoundException(__('Event not found'));
        }

        return $event;
    }

    /**
     * @throws SmsReminderValidationException
     */
    private function toUtc(string $sendAt, EventDomainObject $event): string
    {
        try {
            $local = Carbon::createFromFormat(self::SEND_AT_FORMAT, $sendAt, $event->getTimezone() ?: 'UTC');
        } catch (Throwable) {
            $local = null;
        }

        if (! $local instanceof Carbon) {
            throw new SmsReminderValidationException(__('Invalid sending date'), 'send_at');
        }

        return $local->setSecond(0)->utc()->toDateTimeString();
    }

    private function name(SmsReminderRequestDTO $dto, EventDomainObject $event, string $scheduledAt): string
    {
        $name = trim((string) $dto->name);
        if ($name !== '') {
            return mb_substr($name, 0, 120);
        }

        $local = Carbon::parse($scheduledAt, 'UTC')->setTimezone($event->getTimezone() ?: 'UTC')->locale('fr');

        return mb_substr(__('Reminder').' '.$event->getTitle().', '.$local->isoFormat('D MMMM HH:mm'), 0, 120);
    }
}
