<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\LiveChat\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class LiveChatReplyDTO extends BaseDataObject
{
    public function __construct(
        public readonly string $reply,
        public readonly bool $needsFollowUp,
        public readonly bool $askContact,
        public readonly ?string $reason,
        public readonly bool $refundRequest = false,
    ) {}
}
