<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms;

use HiEvents\DomainObjects\Generated\EventDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\OrganizerDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\QrCampaignDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\QrLinkDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SmsCampaignDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SmsCampaignRecipientDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\QrLinkDomainObject;
use HiEvents\DomainObjects\SmsCampaignDomainObject;
use HiEvents\DomainObjects\SmsCampaignRecipientDomainObject;
use HiEvents\DomainObjects\Status\SmsCampaignRecipientStatus;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Helper\SmsPhoneNormalizer;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Repository\Interfaces\QrCampaignRepositoryInterface;
use HiEvents\Repository\Interfaces\QrLinkRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsCampaignRecipientRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsCampaignRepositoryInterface;
use HiEvents\Services\Domain\Sms\DTO\SmsCampaignImportDTO;
use HiEvents\Services\Domain\Sms\DTO\SmsCampaignImportRecipientDTO;
use HiEvents\Services\Domain\Sms\DTO\SmsCampaignImportResultDTO;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use Throwable;

class SmsCampaignImportService
{
    public function __construct(
        private readonly OrganizerRepositoryInterface $organizerRepository,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly SmsCampaignRepositoryInterface $campaignRepository,
        private readonly SmsCampaignRecipientRepositoryInterface $recipientRepository,
        private readonly QrLinkRepositoryInterface $qrLinkRepository,
        private readonly QrCampaignRepositoryInterface $qrCampaignRepository,
        private readonly SmsCampaignDeliveryService $deliveryService,
        private readonly DatabaseManager $databaseManager,
    ) {}

    /**
     * @throws ResourceNotFoundException
     * @throws Throwable
     */
    public function import(SmsCampaignImportDTO $dto): SmsCampaignImportResultDTO
    {
        /** @var OrganizerDomainObject|null $organizer */
        $organizer = $this->organizerRepository->findFirstWhere([OrganizerDomainObjectAbstract::ID => $dto->organizerId]);
        if ($organizer === null) {
            throw new ResourceNotFoundException(__('Organizer not found'));
        }

        if ($dto->eventId !== null && $this->eventRepository->findFirstWhere([
            EventDomainObjectAbstract::ID => $dto->eventId,
            EventDomainObjectAbstract::ORGANIZER_ID => $dto->organizerId,
        ]) === null) {
            throw new ResourceNotFoundException(__('Event not found'));
        }

        return $this->databaseManager->transaction(function () use ($dto, $organizer) {
            $campaign = $this->findOrCreateCampaign($dto, $organizer);
            $qrLinks = $this->findQrLinks($dto);
            $existing = $this->recipientRepository
                ->findWhere([SmsCampaignRecipientDomainObjectAbstract::SMS_CAMPAIGN_ID => $campaign->getId()])
                ->keyBy(fn (SmsCampaignRecipientDomainObject $recipient) => $recipient->getPhone());

            $created = 0;
            $updated = 0;
            $warnings = [];
            $linkedIds = [];

            foreach ($dto->recipients as $recipient) {
                $phone = SmsPhoneNormalizer::normalize($recipient->phone);
                if ($phone === null) {
                    $warnings[] = $recipient->phone.': '.__('Invalid phone number');

                    continue;
                }

                $qrLink = $recipient->shortId !== null ? $qrLinks[strtolower($recipient->shortId)] ?? null : null;
                if ($recipient->shortId !== null && $qrLink === null) {
                    $warnings[] = $phone.': '.__('QR code not found');
                }

                if ($qrLink !== null) {
                    $linkedIds[] = $qrLink->getId();
                }

                $current = $existing->get($phone);
                if ($current === null) {
                    $this->recipientRepository->create($this->newRecipientAttributes($campaign, $phone, $recipient, $qrLink));
                    $created++;

                    continue;
                }

                $attributes = $this->updatedRecipientAttributes($current, $recipient, $qrLink);
                if ($attributes !== []) {
                    $this->recipientRepository->updateFromArray($current->getId(), $attributes);
                    $updated++;
                }
            }

            if ($linkedIds !== []) {
                $this->qrLinkRepository->updateWhere(
                    attributes: [
                        QrLinkDomainObjectAbstract::SMS_CAMPAIGN_ID => $campaign->getId(),
                        QrLinkDomainObjectAbstract::QR_CAMPAIGN_ID => null,
                    ],
                    where: [
                        QrLinkDomainObjectAbstract::ORGANIZER_ID => $dto->organizerId,
                        [QrLinkDomainObjectAbstract::ID, 'in', array_values(array_unique($linkedIds))],
                    ],
                );
            }

            return new SmsCampaignImportResultDTO(
                campaignId: $campaign->getId(),
                created: $created,
                updated: $updated,
                linkedQrLinks: count(array_unique($linkedIds)),
                warnings: $warnings,
                qrCampaignRetired: $this->retireQrCampaign($dto),
            );
        });
    }

    private function findOrCreateCampaign(SmsCampaignImportDTO $dto, OrganizerDomainObject $organizer): SmsCampaignDomainObject
    {
        /** @var SmsCampaignDomainObject|null $campaign */
        $campaign = $this->campaignRepository->findFirstWhere([
            SmsCampaignDomainObjectAbstract::ORGANIZER_ID => $dto->organizerId,
            SmsCampaignDomainObjectAbstract::NAME => $dto->name,
        ]);

        if ($campaign !== null) {
            $changes = array_filter([
                SmsCampaignDomainObjectAbstract::EVENT_ID => $dto->eventId,
                SmsCampaignDomainObjectAbstract::MESSAGE => $dto->message,
            ], fn ($value) => $value !== null);

            return $changes === [] ? $campaign : $this->campaignRepository->updateFromArray($campaign->getId(), $changes);
        }

        return $this->campaignRepository->create([
            SmsCampaignDomainObjectAbstract::ACCOUNT_ID => $organizer->getAccountId(),
            SmsCampaignDomainObjectAbstract::ORGANIZER_ID => $dto->organizerId,
            SmsCampaignDomainObjectAbstract::EVENT_ID => $dto->eventId,
            SmsCampaignDomainObjectAbstract::NAME => $dto->name,
            SmsCampaignDomainObjectAbstract::MESSAGE => $dto->message,
        ]);
    }

    /**
     * @return array<string, QrLinkDomainObject>
     */
    private function findQrLinks(SmsCampaignImportDTO $dto): array
    {
        $shortIds = array_values(array_unique(array_filter(array_map(
            fn (SmsCampaignImportRecipientDTO $recipient) => $recipient->shortId !== null ? strtolower($recipient->shortId) : null,
            $dto->recipients,
        ))));

        if ($shortIds === []) {
            return [];
        }

        return $this->qrLinkRepository
            ->findWhereIn(QrLinkDomainObjectAbstract::SHORT_ID, $shortIds, [QrLinkDomainObjectAbstract::ORGANIZER_ID => $dto->organizerId])
            ->keyBy(fn (QrLinkDomainObject $qrLink) => $qrLink->getShortId())
            ->all();
    }

    private function newRecipientAttributes(
        SmsCampaignDomainObject $campaign,
        string $phone,
        SmsCampaignImportRecipientDTO $recipient,
        ?QrLinkDomainObject $qrLink,
    ): array {
        $status = SmsCampaignRecipientStatus::fromProvider($recipient->status) ?? SmsCampaignRecipientStatus::PENDING;
        $draft = (new SmsCampaignRecipientDomainObject)->setStatus(SmsCampaignRecipientStatus::PENDING->value);

        return [
            SmsCampaignRecipientDomainObjectAbstract::SMS_CAMPAIGN_ID => $campaign->getId(),
            SmsCampaignRecipientDomainObjectAbstract::PHONE => $phone,
            SmsCampaignRecipientDomainObjectAbstract::CONTACT_NAME => $this->contactName($recipient->contactName),
            SmsCampaignRecipientDomainObjectAbstract::QR_LINK_ID => $qrLink?->getId(),
            SmsCampaignRecipientDomainObjectAbstract::PROVIDER_MESSAGE_ID => $recipient->providerMessageId,
            SmsCampaignRecipientDomainObjectAbstract::STATUS => SmsCampaignRecipientStatus::PENDING->value,
            SmsCampaignRecipientDomainObjectAbstract::SCHEDULED_AT => $this->parseTimestamp($recipient->scheduledAt),
            ...$this->deliveryService->statusAttributes($draft, $status, $recipient->failureReason, $recipient->scheduledAt),
        ];
    }

    private function updatedRecipientAttributes(
        SmsCampaignRecipientDomainObject $current,
        SmsCampaignImportRecipientDTO $recipient,
        ?QrLinkDomainObject $qrLink,
    ): array {
        $status = SmsCampaignRecipientStatus::fromProvider($recipient->status);
        $attributes = $status !== null
            ? $this->deliveryService->statusAttributes($current, $status, $recipient->failureReason, $recipient->scheduledAt)
            : [];

        $candidates = [
            SmsCampaignRecipientDomainObjectAbstract::CONTACT_NAME => [$current->getContactName(), $this->contactName($recipient->contactName)],
            SmsCampaignRecipientDomainObjectAbstract::QR_LINK_ID => [$current->getQrLinkId(), $qrLink?->getId()],
            SmsCampaignRecipientDomainObjectAbstract::PROVIDER_MESSAGE_ID => [$current->getProviderMessageId(), $recipient->providerMessageId],
            SmsCampaignRecipientDomainObjectAbstract::SCHEDULED_AT => [$current->getScheduledAt(), $this->parseTimestamp($recipient->scheduledAt)],
        ];

        foreach ($candidates as $column => [$before, $after]) {
            if ($after !== null && $after !== $before) {
                $attributes[$column] = $after;
            }
        }

        return $attributes;
    }

    private function retireQrCampaign(SmsCampaignImportDTO $dto): bool
    {
        if ($dto->retireQrCampaignId === null) {
            return false;
        }

        $this->qrLinkRepository->updateWhere(
            attributes: [QrLinkDomainObjectAbstract::QR_CAMPAIGN_ID => null],
            where: [
                QrLinkDomainObjectAbstract::QR_CAMPAIGN_ID => $dto->retireQrCampaignId,
                QrLinkDomainObjectAbstract::ORGANIZER_ID => $dto->organizerId,
            ],
        );

        return $this->qrCampaignRepository->deleteWhere([
            QrCampaignDomainObjectAbstract::ID => $dto->retireQrCampaignId,
            QrCampaignDomainObjectAbstract::ORGANIZER_ID => $dto->organizerId,
        ]) > 0;
    }

    private function contactName(?string $name): ?string
    {
        $name = trim((string) $name);

        return $name === '' ? null : mb_substr($name, 0, 150);
    }

    private function parseTimestamp(?string $timestamp): ?string
    {
        if ($timestamp === null || $timestamp === '') {
            return null;
        }

        try {
            return Carbon::parse($timestamp)->utc()->toDateTimeString();
        } catch (Throwable) {
            return null;
        }
    }
}
