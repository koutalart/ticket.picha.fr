<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms;

use HiEvents\DomainObjects\Enums\SmsMessageAuthorType;
use HiEvents\DomainObjects\Enums\SmsMessageDirection;
use HiEvents\DomainObjects\Enums\SmsOptOutSource;
use HiEvents\DomainObjects\Generated\SmsMessageDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SmsOptOutDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerSmsSettingDomainObject;
use HiEvents\DomainObjects\SmsMessageDomainObject;
use HiEvents\DomainObjects\Status\SmsMessageStatus;
use HiEvents\Exceptions\InvalidSmsPhoneNumberException;
use HiEvents\Helper\SmsKeywordDetector;
use HiEvents\Helper\SmsPhoneNormalizer;
use HiEvents\Repository\Interfaces\SmsMessageRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsOptOutRepositoryInterface;
use HiEvents\Services\Domain\Sms\DTO\InboundSmsDTO;
use HiEvents\Services\Domain\Sms\DTO\OutboundSmsEventDTO;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use Throwable;

class SmsInboundService
{
    private const STATUS_RANK = [
        'PENDING' => 0,
        'SENT' => 1,
        'DELIVERED' => 2,
        'FAILED' => 2,
        'EXPIRED' => 2,
    ];

    public function __construct(
        private readonly SmsMessageRepositoryInterface $messageRepository,
        private readonly SmsOptOutRepositoryInterface $optOutRepository,
        private readonly SmsConversationService $conversationService,
        private readonly DatabaseManager $databaseManager,
    ) {}

    /**
     * @throws InvalidSmsPhoneNumberException
     * @throws Throwable
     */
    public function recordInbound(OrganizerSmsSettingDomainObject $settings, InboundSmsDTO $sms): ?SmsMessageDomainObject
    {
        if ($this->findByProviderId($sms->providerMessageId) !== null) {
            return null;
        }

        $phone = $this->normalize($sms->contact);

        return $this->databaseManager->transaction(function () use ($settings, $sms, $phone) {
            $conversation = $this->conversationService->findOrCreate($settings, $phone);

            /** @var SmsMessageDomainObject $message */
            $message = $this->messageRepository->create([
                SmsMessageDomainObjectAbstract::SMS_CONVERSATION_ID => $conversation->getId(),
                SmsMessageDomainObjectAbstract::ORGANIZER_ID => $settings->getOrganizerId(),
                SmsMessageDomainObjectAbstract::DIRECTION => SmsMessageDirection::INBOUND->name,
                SmsMessageDomainObjectAbstract::AUTHOR_TYPE => SmsMessageAuthorType::CONTACT->name,
                SmsMessageDomainObjectAbstract::CONTENT => $sms->content,
                SmsMessageDomainObjectAbstract::STATUS => SmsMessageStatus::RECEIVED->name,
                SmsMessageDomainObjectAbstract::PROVIDER_MESSAGE_ID => $sms->providerMessageId,
                SmsMessageDomainObjectAbstract::PROVIDER_TIMESTAMP => $this->parseTimestamp($sms->timestamp),
            ]);

            $this->conversationService->recordActivity($conversation, SmsMessageDirection::INBOUND, $sms->content, markUnread: true);
            $this->applyKeyword($settings->getOrganizerId(), $phone, $sms->content);

            return $message;
        });
    }

    /**
     * @throws InvalidSmsPhoneNumberException
     * @throws Throwable
     */
    public function recordOutboundEvent(OrganizerSmsSettingDomainObject $settings, OutboundSmsEventDTO $event): SmsMessageDomainObject
    {
        $existing = $this->findByProviderId($event->providerMessageId)
            ?? $this->findByRequestId($settings->getOrganizerId(), $event->requestId);

        if ($existing !== null) {
            return $this->updateStatus($existing, $event);
        }

        $phone = $this->normalize($event->contact);

        return $this->databaseManager->transaction(function () use ($settings, $event, $phone) {
            $conversation = $this->conversationService->findOrCreate($settings, $phone);
            $content = (string) $event->content;

            /** @var SmsMessageDomainObject $message */
            $message = $this->messageRepository->create([
                SmsMessageDomainObjectAbstract::SMS_CONVERSATION_ID => $conversation->getId(),
                SmsMessageDomainObjectAbstract::ORGANIZER_ID => $settings->getOrganizerId(),
                SmsMessageDomainObjectAbstract::DIRECTION => SmsMessageDirection::OUTBOUND->name,
                SmsMessageDomainObjectAbstract::AUTHOR_TYPE => SmsMessageAuthorType::CAMPAIGN->name,
                SmsMessageDomainObjectAbstract::CONTENT => $content,
                SmsMessageDomainObjectAbstract::STATUS => $event->status->name,
                SmsMessageDomainObjectAbstract::PROVIDER_MESSAGE_ID => $event->providerMessageId,
                SmsMessageDomainObjectAbstract::REQUEST_ID => $event->requestId,
                SmsMessageDomainObjectAbstract::ERROR_MESSAGE => $this->truncateError($event->errorMessage),
                SmsMessageDomainObjectAbstract::PROVIDER_TIMESTAMP => $this->parseTimestamp($event->timestamp),
            ]);

            $this->conversationService->recordActivity($conversation, SmsMessageDirection::OUTBOUND, $content, markUnread: false);

            return $message;
        });
    }

    private function updateStatus(SmsMessageDomainObject $message, OutboundSmsEventDTO $event): SmsMessageDomainObject
    {
        $currentRank = self::STATUS_RANK[$message->getStatus()] ?? 0;
        $newRank = self::STATUS_RANK[$event->status->name] ?? 0;

        $attributes = [];
        if ($message->getProviderMessageId() === null) {
            $attributes[SmsMessageDomainObjectAbstract::PROVIDER_MESSAGE_ID] = $event->providerMessageId;
        }

        if ($newRank > $currentRank) {
            $attributes[SmsMessageDomainObjectAbstract::STATUS] = $event->status->name;
            $attributes[SmsMessageDomainObjectAbstract::ERROR_MESSAGE] = $this->truncateError($event->errorMessage);
            $attributes[SmsMessageDomainObjectAbstract::PROVIDER_TIMESTAMP] = $this->parseTimestamp($event->timestamp);
        }

        if ($attributes === []) {
            return $message;
        }

        return $this->messageRepository->updateFromArray($message->getId(), $attributes);
    }

    private function applyKeyword(int $organizerId, string $phone, string $content): void
    {
        $keyword = SmsKeywordDetector::detect($content);
        $where = [
            SmsOptOutDomainObjectAbstract::ORGANIZER_ID => $organizerId,
            SmsOptOutDomainObjectAbstract::PHONE => $phone,
        ];

        if ($keyword === SmsKeywordDetector::STOP && ! $this->conversationService->isOptedOut($organizerId, $phone)) {
            $this->optOutRepository->create([
                ...$where,
                SmsOptOutDomainObjectAbstract::SOURCE => SmsOptOutSource::KEYWORD->name,
            ]);
        }

        if ($keyword === SmsKeywordDetector::START) {
            $this->optOutRepository->deleteWhere($where);
        }
    }

    private function findByProviderId(string $providerMessageId): ?SmsMessageDomainObject
    {
        return $this->messageRepository->findFirstWhere([
            SmsMessageDomainObjectAbstract::PROVIDER_MESSAGE_ID => $providerMessageId,
        ]);
    }

    private function findByRequestId(int $organizerId, ?string $requestId): ?SmsMessageDomainObject
    {
        if ($requestId === null || ! str_starts_with($requestId, SmsSendService::REQUEST_ID_PREFIX)) {
            return null;
        }

        return $this->messageRepository->findFirstWhere([
            SmsMessageDomainObjectAbstract::ORGANIZER_ID => $organizerId,
            SmsMessageDomainObjectAbstract::REQUEST_ID => $requestId,
        ]);
    }

    /**
     * @throws InvalidSmsPhoneNumberException
     */
    private function normalize(string $contact): string
    {
        $phone = SmsPhoneNormalizer::normalize($contact);

        if ($phone === null) {
            throw new InvalidSmsPhoneNumberException(__('Invalid phone number'));
        }

        return $phone;
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

    private function truncateError(?string $error): ?string
    {
        return $error === null ? null : mb_substr($error, 0, 500);
    }
}
