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
        'EXPIRED' => 1,
        'FAILED' => 1,
        'SENT' => 2,
        'DELIVERED' => 3,
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
    public function recordInbound(
        OrganizerSmsSettingDomainObject $settings,
        InboundSmsDTO $sms,
        bool $fromHistory = false,
        ?int $lineSettingsId = null,
    ): ?SmsMessageDomainObject {
        if ($this->findByProviderId($sms->providerMessageId) !== null) {
            return null;
        }

        $lineSettingsId ??= self::lineOf($settings);
        $phone = $this->normalize($sms->contact);

        return $this->databaseManager->transaction(function () use ($settings, $sms, $phone, $fromHistory, $lineSettingsId) {
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
                SmsMessageDomainObjectAbstract::LINE_SETTINGS_ID => $lineSettingsId,
            ]);

            $this->conversationService->recordActivity(
                $conversation,
                SmsMessageDirection::INBOUND,
                $sms->content,
                markUnread: ! $fromHistory,
                occurredAt: $fromHistory ? $this->parseTimestamp($sms->timestamp) : null,
                lineSettingsId: $lineSettingsId,
            );
            $this->applyKeyword($settings->getOrganizerId(), $phone, $sms->content);

            return $message;
        });
    }

    /**
     * Updates the status of the message the event is about, looked up only among the given organizers
     * (the organizers of the line, the settings organizer by default). Unknown messages are stored for the settings organizer.
     *
     * @param  array<int, int>|null  $organizerIds
     *
     * @throws InvalidSmsPhoneNumberException
     * @throws Throwable
     */
    public function recordOutboundEvent(
        OrganizerSmsSettingDomainObject $settings,
        OutboundSmsEventDTO $event,
        bool $fromHistory = false,
        ?array $organizerIds = null,
        ?int $lineSettingsId = null,
    ): ?SmsMessageDomainObject {
        $organizerIds ??= [$settings->getOrganizerId()];

        $byProviderId = $this->findByProviderId($event->providerMessageId);
        if ($byProviderId !== null && ! in_array($byProviderId->getOrganizerId(), $organizerIds, true)) {
            return null;
        }

        $existing = $byProviderId ?? $this->findByRequestId($organizerIds, $event->requestId);

        if ($existing !== null) {
            return $this->updateStatus($existing, $event);
        }

        $lineSettingsId ??= self::lineOf($settings);
        $phone = $this->normalize($event->contact);

        return $this->databaseManager->transaction(function () use ($settings, $event, $phone, $fromHistory, $lineSettingsId) {
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
                SmsMessageDomainObjectAbstract::LINE_SETTINGS_ID => $lineSettingsId,
            ]);

            $this->conversationService->recordActivity(
                $conversation,
                SmsMessageDirection::OUTBOUND,
                $content,
                markUnread: false,
                occurredAt: $fromHistory ? $this->parseTimestamp($event->timestamp) : null,
                lineSettingsId: $lineSettingsId,
            );

            return $message;
        });
    }

    private static function lineOf(OrganizerSmsSettingDomainObject $settings): int
    {
        return $settings->getSharedLineSettingsId() ?? $settings->getId();
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

    private function findByRequestId(array $organizerIds, ?string $requestId): ?SmsMessageDomainObject
    {
        if ($requestId === null || ! str_starts_with($requestId, SmsSendService::REQUEST_ID_PREFIX)) {
            return null;
        }

        $organizerIds = array_values($organizerIds);

        return $this->messageRepository->findFirstWhere(count($organizerIds) === 1
            ? [
                SmsMessageDomainObjectAbstract::ORGANIZER_ID => $organizerIds[0],
                SmsMessageDomainObjectAbstract::REQUEST_ID => $requestId,
            ]
            : [
                [SmsMessageDomainObjectAbstract::ORGANIZER_ID, 'in', $organizerIds],
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
