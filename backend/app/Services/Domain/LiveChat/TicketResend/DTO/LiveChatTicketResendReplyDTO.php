<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\LiveChat\TicketResend\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class LiveChatTicketResendReplyDTO extends BaseDataObject
{
    /**
     * @param  list<string>  $messages  bubbles shown to the visitor
     */
    public function __construct(
        public readonly array $messages,
        public readonly bool $needsFollowUp = false,
        public readonly ?string $reason = null,
    ) {}
}
