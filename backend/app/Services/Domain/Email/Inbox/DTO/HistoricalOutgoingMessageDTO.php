<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Email\Inbox\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class HistoricalOutgoingMessageDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $outgoingMessageId,
        public readonly int $accountId,
        public readonly int $organizerId,
        public readonly int $eventId,
        public readonly ?int $orderId,
        public readonly ?int $sentByUserId,
        public readonly string $recipient,
        public readonly ?string $subject,
        public readonly ?string $messageHtml,
        public readonly string $status,
        public readonly string $createdAt,
    ) {}
}
