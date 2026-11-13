<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class SmsInternalInboxMoveDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $conversationId,
        public readonly int $organizerId,
        public readonly string $phone,
        public readonly int $messages,
        public readonly ?int $targetConversationId = null,
    ) {}
}
