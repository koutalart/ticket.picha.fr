<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Email\Inbox;

use HiEvents\DomainObjects\EmailMessageDomainObject;
use HiEvents\DomainObjects\Enums\EmailMessageDirection;
use HiEvents\DomainObjects\Generated\EmailMessageDomainObjectAbstract;
use HiEvents\DomainObjects\Status\EmailMessageStatus;
use HiEvents\Mail\Event\EventMessage;
use HiEvents\Repository\Interfaces\EmailMessageRepositoryInterface;
use HiEvents\Repository\Interfaces\EmailThreadRepositoryInterface;
use HiEvents\Services\Domain\Email\Inbox\DTO\HistoricalOutgoingMessageDTO;
use HiEvents\Services\Domain\Email\Inbox\DTO\OutgoingMessageImportResultDTO;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Brings the event messages sent before the e-mail log existed (table outgoing_messages) into the threads.
 * Order confirmations and tickets were never logged before, so the log only holds them from its deployment.
 * Safe to run again: a message already imported, or sent after the live log started, is skipped.
 */
class OutgoingMessageImportService
{
    private const BATCH_SIZE = 500;

    public function __construct(
        private readonly EmailMessageRepositoryInterface $messageRepository,
        private readonly EmailThreadRepositoryInterface $threadRepository,
        private readonly EmailThreadService $threadService,
    ) {}

    public function import(?int $organizerId = null, bool $dryRun = false): OutgoingMessageImportResultDTO
    {
        $source = class_basename(EventMessage::class);
        $sentBefore = $this->messageRepository->findFirstLoggedAt($source) ?? now()->toDateTimeString();
        $imported = 0;
        $skipped = 0;
        $afterId = 0;

        do {
            $rows = $this->messageRepository->findUnimportedOutgoingMessages($afterId, $sentBefore, $organizerId, self::BATCH_SIZE);

            foreach ($rows as $row) {
                $afterId = $row->outgoingMessageId;

                if ($dryRun) {
                    $imported++;

                    continue;
                }

                $this->importOne($row, $source) ? $imported++ : $skipped++;
            }
        } while ($rows->count() === self::BATCH_SIZE);

        return new OutgoingMessageImportResultDTO(imported: $imported, skipped: $skipped, sentBefore: $sentBefore);
    }

    private function importOne(HistoricalOutgoingMessageDTO $row, string $source): bool
    {
        $email = mb_strtolower(trim($row->recipient));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || $this->threadRepository->isAccountUserEmail($row->accountId, $email)) {
            return false;
        }

        $thread = $this->threadService->findOrCreate($row->accountId, $row->organizerId, $email);
        $text = $row->messageHtml !== null
            ? html_entity_decode(strip_tags(preg_replace('/<br\s*\/?>|<\/p>/i', "\n", $row->messageHtml) ?? ''), ENT_QUOTES | ENT_HTML5)
            : null;

        try {
            /** @var EmailMessageDomainObject $message */
            $message = $this->messageRepository->create([
                EmailMessageDomainObjectAbstract::EMAIL_THREAD_ID => $thread->getId(),
                EmailMessageDomainObjectAbstract::ORGANIZER_ID => $row->organizerId,
                EmailMessageDomainObjectAbstract::EVENT_ID => $row->eventId,
                EmailMessageDomainObjectAbstract::ORDER_ID => $row->orderId,
                EmailMessageDomainObjectAbstract::OUTGOING_MESSAGE_ID => $row->outgoingMessageId,
                EmailMessageDomainObjectAbstract::DIRECTION => EmailMessageDirection::OUTBOUND->name,
                EmailMessageDomainObjectAbstract::SOURCE => $source,
                EmailMessageDomainObjectAbstract::SENT_BY_USER_ID => $row->sentByUserId,
                EmailMessageDomainObjectAbstract::TO_EMAIL => $email,
                EmailMessageDomainObjectAbstract::SUBJECT => $row->subject !== null ? mb_substr($row->subject, 0, 255) : null,
                EmailMessageDomainObjectAbstract::PREVIEW => EmailThreadService::preview($text),
                EmailMessageDomainObjectAbstract::STATUS => $row->status === 'FAILED' ? EmailMessageStatus::FAILED->name : EmailMessageStatus::SENT->name,
                EmailMessageDomainObjectAbstract::STATUS_UPDATED_AT => $row->createdAt,
                EmailMessageDomainObjectAbstract::CREATED_AT => $row->createdAt,
                EmailMessageDomainObjectAbstract::UPDATED_AT => $row->createdAt,
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        if ($thread->getLastMessageAt() === null || $row->createdAt >= $thread->getLastMessageAt()) {
            $this->threadService->applyMessage($thread, $message);
        }

        return true;
    }
}
