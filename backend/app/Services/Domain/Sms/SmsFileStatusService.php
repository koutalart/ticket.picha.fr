<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms;

use HiEvents\DomainObjects\Generated\SmsCampaignRecipientDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SmsMessageDomainObjectAbstract;
use HiEvents\DomainObjects\SmsCampaignRecipientDomainObject;
use HiEvents\DomainObjects\SmsMessageDomainObject;
use HiEvents\DomainObjects\Status\SmsMessageStatus;
use HiEvents\Repository\Interfaces\SmsCampaignRecipientRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsMessageRepositoryInterface;
use HiEvents\Services\Infrastructure\SmsFile\DTO\SmsFileResultDTO;
use Illuminate\Support\Carbon;

/**
 * Links an SMS handed to the central queue to its sms.picha.fr message (id_sms_picha becomes the provider id, so
 * the existing webhook keeps updating it) and moves its status forward, never backward.
 */
class SmsFileStatusService
{
    /** The queue sends to sms.picha.fr with request_id « file-{queue id} ». */
    public const QUEUE_REQUEST_ID_PREFIX = 'file-';

    private const MESSAGE_RANK = [
        'QUEUED' => 0,
        'PENDING' => 1,
        'EXPIRED' => 2,
        'FAILED' => 2,
        'SENT' => 3,
        'DELIVERED' => 4,
    ];

    public function __construct(
        private readonly SmsMessageRepositoryInterface $messageRepository,
        private readonly SmsCampaignRecipientRepositoryInterface $recipientRepository,
        private readonly SmsCampaignDeliveryService $deliveryService,
    ) {}

    public static function queueIdFromRequestId(?string $requestId): ?int
    {
        if ($requestId === null || ! str_starts_with($requestId, self::QUEUE_REQUEST_ID_PREFIX)) {
            return null;
        }

        $id = substr($requestId, strlen(self::QUEUE_REQUEST_ID_PREFIX));

        return ctype_digit($id) ? (int) $id : null;
    }

    public function messageAttributes(?string $currentStatus, ?string $currentProviderId, SmsFileResultDTO $result): array
    {
        $attributes = [
            SmsMessageDomainObjectAbstract::QUEUE_MESSAGE_ID => $result->id,
            SmsMessageDomainObjectAbstract::QUEUE_CHECKED_AT => Carbon::now('UTC')->toDateTimeString(),
        ];

        if ($result->providerMessageId !== null && $currentProviderId === null) {
            $attributes[SmsMessageDomainObjectAbstract::PROVIDER_MESSAGE_ID] = $result->providerMessageId;
        }

        $status = $result->state->messageStatus();
        $currentRank = $currentStatus === null ? -1 : (self::MESSAGE_RANK[$currentStatus] ?? 0);
        if (self::MESSAGE_RANK[$status->name] > $currentRank) {
            $attributes[SmsMessageDomainObjectAbstract::STATUS] = $status->name;
            if (in_array($status, [SmsMessageStatus::FAILED, SmsMessageStatus::EXPIRED], true)) {
                $attributes[SmsMessageDomainObjectAbstract::ERROR_MESSAGE] = $result->reason;
            }
        }

        return $attributes;
    }

    public function applyToMessage(SmsMessageDomainObject $message, SmsFileResultDTO $result): SmsMessageDomainObject
    {
        return $this->messageRepository->updateFromArray(
            $message->getId(),
            $this->messageAttributes($message->getStatus(), $message->getProviderMessageId(), $result),
        );
    }

    public function applyToRecipient(SmsCampaignRecipientDomainObject $recipient, SmsFileResultDTO $result): void
    {
        $attributes = [
            ...$this->deliveryService->statusAttributes($recipient, $result->state->recipientStatus(), $result->reason),
            SmsCampaignRecipientDomainObjectAbstract::QUEUE_MESSAGE_ID => $result->id,
        ];

        if ($result->providerMessageId !== null && $recipient->getProviderMessageId() === null) {
            $attributes[SmsCampaignRecipientDomainObjectAbstract::PROVIDER_MESSAGE_ID] = $result->providerMessageId;
        }

        if ($recipient->getFailureReason() !== null && ! array_key_exists(SmsCampaignRecipientDomainObjectAbstract::FAILURE_REASON, $attributes)) {
            $attributes[SmsCampaignRecipientDomainObjectAbstract::FAILURE_REASON] = null;
        }

        $this->recipientRepository->updateFromArray($recipient->getId(), $attributes);
    }
}
