<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms;

use HiEvents\DomainObjects\Enums\SmsMessageDirection;
use HiEvents\DomainObjects\Generated\SmsConversationDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SmsOptOutDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerSmsSettingDomainObject;
use HiEvents\DomainObjects\SmsConversationDomainObject;
use HiEvents\DomainObjects\Status\SmsConversationStatus;
use HiEvents\Repository\Interfaces\SmsConversationRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsOptOutRepositoryInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

class SmsConversationService
{
    private const PREVIEW_LENGTH = 160;

    public function __construct(
        private readonly SmsConversationRepositoryInterface $conversationRepository,
        private readonly SmsOptOutRepositoryInterface $optOutRepository,
    ) {}

    public function findOrCreate(OrganizerSmsSettingDomainObject $settings, string $phone): SmsConversationDomainObject
    {
        $where = [
            SmsConversationDomainObjectAbstract::ORGANIZER_ID => $settings->getOrganizerId(),
            SmsConversationDomainObjectAbstract::PHONE => $phone,
        ];

        /** @var SmsConversationDomainObject|null $existing */
        $existing = $this->conversationRepository->findFirstWhere($where);
        if ($existing !== null) {
            return $existing;
        }

        try {
            return $this->conversationRepository->create([
                ...$where,
                SmsConversationDomainObjectAbstract::ACCOUNT_ID => $settings->getAccountId(),
                SmsConversationDomainObjectAbstract::CONTACT_NAME => $this->conversationRepository
                    ->findContactNameByPhone($settings->getOrganizerId(), $phone),
                SmsConversationDomainObjectAbstract::STATUS => SmsConversationStatus::OPEN->name,
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->conversationRepository->findFirstWhere($where);
        }
    }

    public function recordActivity(
        SmsConversationDomainObject $conversation,
        SmsMessageDirection $direction,
        string $content,
        bool $markUnread,
        ?string $occurredAt = null,
        ?int $lineSettingsId = null,
    ): SmsConversationDomainObject {
        if ($occurredAt !== null && $this->isOlderThanLastMessage($conversation, $occurredAt)) {
            return $conversation;
        }

        $attributes = [
            SmsConversationDomainObjectAbstract::LAST_MESSAGE_AT => $occurredAt ?? now()->toDateTimeString(),
            SmsConversationDomainObjectAbstract::LAST_MESSAGE_PREVIEW => mb_substr($content, 0, self::PREVIEW_LENGTH),
            SmsConversationDomainObjectAbstract::LAST_MESSAGE_DIRECTION => $direction->name,
        ];

        if ($lineSettingsId !== null && $lineSettingsId !== $conversation->getLineSettingsId()) {
            $attributes[SmsConversationDomainObjectAbstract::LINE_SETTINGS_ID] = $lineSettingsId;
        }

        if ($markUnread) {
            $attributes[SmsConversationDomainObjectAbstract::UNREAD_COUNT] = $conversation->getUnreadCount() + 1;
        }

        if ($occurredAt === null && $direction === SmsMessageDirection::INBOUND && $conversation->getStatus() === SmsConversationStatus::CLOSED->name) {
            $attributes[SmsConversationDomainObjectAbstract::STATUS] = SmsConversationStatus::OPEN->name;
        }

        return $this->conversationRepository->updateFromArray($conversation->getId(), $attributes);
    }

    private function isOlderThanLastMessage(SmsConversationDomainObject $conversation, string $occurredAt): bool
    {
        $lastMessageAt = $conversation->getLastMessageAt();

        return $lastMessageAt !== null && Carbon::parse($lastMessageAt)->gte(Carbon::parse($occurredAt));
    }

    public function isOptedOut(int $organizerId, string $phone): bool
    {
        return $this->optOutRepository->findFirstWhere([
            SmsOptOutDomainObjectAbstract::ORGANIZER_ID => $organizerId,
            SmsOptOutDomainObjectAbstract::PHONE => $phone,
        ]) !== null;
    }
}
