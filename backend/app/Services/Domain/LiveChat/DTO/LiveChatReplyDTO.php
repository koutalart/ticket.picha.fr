<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\LiveChat\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class LiveChatReplyDTO extends BaseDataObject
{
    /**
     * @param  list<string>  $messages  1 to 3 short bubbles, shown one after the other
     */
    public function __construct(
        public readonly array $messages,
        public readonly bool $needsFollowUp,
        public readonly bool $askContact,
        public readonly ?string $reason,
        public readonly bool $refundRequest = false,
    ) {}

    public function text(): string
    {
        return implode("\n", $this->messages);
    }
}
