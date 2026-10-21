<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms;

use HiEvents\DomainObjects\Generated\SmsCampaignDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SmsCampaignRecipientDomainObjectAbstract;
use HiEvents\DomainObjects\SmsCampaignRecipientDomainObject;
use HiEvents\DomainObjects\Status\SmsCampaignRecipientStatus;
use HiEvents\Repository\Interfaces\SmsCampaignRecipientRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsCampaignRepositoryInterface;
use Illuminate\Support\Carbon;
use Throwable;

class SmsCampaignDeliveryService
{
    private const STATUS_RANK = [
        'PENDING' => 0,
        'SCHEDULED' => 0,
        'SENT' => 1,
        'DELIVERED' => 2,
        'FAILED' => 2,
        'EXPIRED' => 2,
    ];

    public function __construct(
        private readonly SmsCampaignRecipientRepositoryInterface $recipientRepository,
        private readonly SmsCampaignRepositoryInterface $campaignRepository,
    ) {}

    public function applyProviderStatus(
        int $organizerId,
        string $providerMessageId,
        SmsCampaignRecipientStatus $status,
        ?string $failureReason = null,
        ?string $timestamp = null,
    ): bool {
        /** @var SmsCampaignRecipientDomainObject|null $recipient */
        $recipient = $this->recipientRepository->findFirstWhere([
            SmsCampaignRecipientDomainObjectAbstract::PROVIDER_MESSAGE_ID => $providerMessageId,
        ]);

        if ($recipient === null || ! $this->belongsToOrganizer($recipient, $organizerId)) {
            return false;
        }

        $attributes = $this->statusAttributes($recipient, $status, $failureReason, $timestamp);
        if ($attributes === []) {
            return false;
        }

        $this->recipientRepository->updateFromArray($recipient->getId(), $attributes);

        return true;
    }

    public function statusAttributes(
        SmsCampaignRecipientDomainObject $recipient,
        SmsCampaignRecipientStatus $status,
        ?string $failureReason = null,
        ?string $timestamp = null,
    ): array {
        if (! $this->isProgress($recipient->getStatus(), $status)) {
            return [];
        }

        $at = $this->parseTimestamp($timestamp);
        $attributes = [SmsCampaignRecipientDomainObjectAbstract::STATUS => $status->value];

        if ($status === SmsCampaignRecipientStatus::SENT) {
            $attributes[SmsCampaignRecipientDomainObjectAbstract::SENT_AT] = $at;
        }

        if ($status === SmsCampaignRecipientStatus::DELIVERED) {
            $attributes[SmsCampaignRecipientDomainObjectAbstract::DELIVERED_AT] = $at;
            $attributes[SmsCampaignRecipientDomainObjectAbstract::SENT_AT] = $recipient->getSentAt() ?? $at;
        }

        if (in_array($status, [SmsCampaignRecipientStatus::FAILED, SmsCampaignRecipientStatus::EXPIRED], true)) {
            $attributes[SmsCampaignRecipientDomainObjectAbstract::FAILURE_REASON] = $failureReason !== null
                ? mb_substr($failureReason, 0, 500)
                : null;
        }

        return $attributes;
    }

    private function isProgress(string $current, SmsCampaignRecipientStatus $next): bool
    {
        $currentRank = self::STATUS_RANK[$current] ?? 0;
        $nextRank = self::STATUS_RANK[$next->value];

        if ($nextRank === $currentRank) {
            return $current === SmsCampaignRecipientStatus::PENDING->value && $next === SmsCampaignRecipientStatus::SCHEDULED;
        }

        return $nextRank > $currentRank;
    }

    private function belongsToOrganizer(SmsCampaignRecipientDomainObject $recipient, int $organizerId): bool
    {
        return $this->campaignRepository->findFirstWhere([
            SmsCampaignDomainObjectAbstract::ID => $recipient->getSmsCampaignId(),
            SmsCampaignDomainObjectAbstract::ORGANIZER_ID => $organizerId,
        ]) !== null;
    }

    private function parseTimestamp(?string $timestamp): string
    {
        try {
            return ($timestamp ? Carbon::parse($timestamp) : Carbon::now())->utc()->toDateTimeString();
        } catch (Throwable) {
            return Carbon::now()->utc()->toDateTimeString();
        }
    }
}
