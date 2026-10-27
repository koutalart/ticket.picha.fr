<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Email\Inbox;

use HiEvents\DomainObjects\EmailMessageDomainObject;
use HiEvents\DomainObjects\EmailThreadDomainObject;
use HiEvents\DomainObjects\Enums\EmailMessageDirection;
use HiEvents\DomainObjects\Generated\EmailThreadDomainObjectAbstract;
use HiEvents\DomainObjects\Status\EmailThreadStatus;
use HiEvents\Repository\Interfaces\EmailThreadRepositoryInterface;
use Illuminate\Database\UniqueConstraintViolationException;

class EmailThreadService
{
    public const PREVIEW_LENGTH = 200;

    public function __construct(
        private readonly EmailThreadRepositoryInterface $threadRepository,
    ) {}

    public function findOrCreate(int $accountId, int $organizerId, string $email, ?string $contactName = null): EmailThreadDomainObject
    {
        $email = mb_strtolower(trim($email));
        $where = [
            EmailThreadDomainObjectAbstract::ORGANIZER_ID => $organizerId,
            EmailThreadDomainObjectAbstract::EMAIL => $email,
        ];

        /** @var EmailThreadDomainObject|null $thread */
        $thread = $this->threadRepository->findFirstWhere($where);

        if ($thread !== null) {
            if ($thread->getContactName() === null && $contactName !== null && trim($contactName) !== '') {
                $thread = $this->threadRepository->updateFromArray($thread->getId(), [
                    EmailThreadDomainObjectAbstract::CONTACT_NAME => mb_substr(trim($contactName), 0, 150),
                ]);
            }

            return $thread;
        }

        try {
            return $this->threadRepository->create([
                ...$where,
                EmailThreadDomainObjectAbstract::ACCOUNT_ID => $accountId,
                EmailThreadDomainObjectAbstract::CONTACT_NAME => $contactName !== null && trim($contactName) !== ''
                    ? mb_substr(trim($contactName), 0, 150)
                    : $this->threadRepository->findNameForEmail($organizerId, $email),
                EmailThreadDomainObjectAbstract::STATUS => EmailThreadStatus::OPEN->name,
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->threadRepository->findFirstWhere($where);
        }
    }

    public function applyMessage(EmailThreadDomainObject $thread, EmailMessageDomainObject $message, bool $answersCustomer = false): EmailThreadDomainObject
    {
        $inbound = $message->getDirection() === EmailMessageDirection::INBOUND->name;

        $attributes = [
            EmailThreadDomainObjectAbstract::LAST_MESSAGE_AT => $message->getCreatedAt() ?? now()->toDateTimeString(),
            EmailThreadDomainObjectAbstract::LAST_MESSAGE_SUBJECT => $message->getSubject() !== null ? mb_substr($message->getSubject(), 0, 255) : null,
            EmailThreadDomainObjectAbstract::LAST_MESSAGE_PREVIEW => $message->getPreview() !== null ? mb_substr($message->getPreview(), 0, self::PREVIEW_LENGTH) : null,
            EmailThreadDomainObjectAbstract::LAST_MESSAGE_DIRECTION => $message->getDirection(),
            EmailThreadDomainObjectAbstract::LAST_MESSAGE_STATUS => $message->getStatus(),
        ];

        if ($inbound) {
            $attributes[EmailThreadDomainObjectAbstract::UNREAD_COUNT] = $thread->getUnreadCount() + 1;
            $attributes[EmailThreadDomainObjectAbstract::STATUS] = EmailThreadStatus::NEEDS_HUMAN->name;
        } elseif ($answersCustomer && $thread->getStatus() === EmailThreadStatus::NEEDS_HUMAN->name) {
            $attributes[EmailThreadDomainObjectAbstract::STATUS] = EmailThreadStatus::OPEN->name;
        }

        return $this->threadRepository->updateFromArray($thread->getId(), $attributes);
    }

    public function refreshLastStatus(EmailMessageDomainObject $message, string $status): void
    {
        /** @var EmailThreadDomainObject|null $thread */
        $thread = $this->threadRepository->findFirst($message->getEmailThreadId());

        if ($thread === null
            || $thread->getLastMessageDirection() !== EmailMessageDirection::OUTBOUND->name
            || ($thread->getLastMessageAt() !== null && $message->getCreatedAt() !== null && $message->getCreatedAt() < $thread->getLastMessageAt())) {
            return;
        }

        $this->threadRepository->updateFromArray($thread->getId(), [
            EmailThreadDomainObjectAbstract::LAST_MESSAGE_STATUS => $status,
        ]);
    }

    public static function preview(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $compact = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        return $compact !== '' ? mb_substr($compact, 0, 500) : null;
    }
}
