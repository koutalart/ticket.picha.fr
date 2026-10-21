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
    ): SmsConversationDomainObject {
        $attributes = [
            SmsConversationDomainObjectAbstract::LAST_MESSAGE_AT => now()->toDateTimeString(),
            SmsConversationDomainObjectAbstract::LAST_MESSAGE_PREVIEW => mb_substr($content, 0, self::PREVIEW_LENGTH),
            SmsConversationDomainObjectAbstract::LAST_MESSAGE_DIRECTION => $direction->name,
        ];

        if ($markUnread) {
            $attributes[SmsConversationDomainObjectAbstract::UNREAD_COUNT] = $conversation->getUnreadCount() + 1;
        }

        if ($direction === SmsMessageDirection::INBOUND && $conversation->getStatus() === SmsConversationStatus::CLOSED->name) {
            $attributes[SmsConversationDomainObjectAbstract::STATUS] = SmsConversationStatus::OPEN->name;
        }

        return $this->conversationRepository->updateFromArray($conversation->getId(), $attributes);
    }

    public function isOptedOut(int $organizerId, string $phone): bool
    {
        return $this->optOutRepository->findFirstWhere([
            SmsOptOutDomainObjectAbstract::ORGANIZER_ID => $organizerId,
            SmsOptOutDomainObjectAbstract::PHONE => $phone,
        ]) !== null;
    }
}
