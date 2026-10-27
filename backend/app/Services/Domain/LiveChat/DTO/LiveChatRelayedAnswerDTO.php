<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\LiveChat\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class LiveChatRelayedAnswerDTO extends BaseDataObject
{
    /**
     * @param  list<string>  $messages  the bubbles that give the organizer's answer to the visitor
     * @param  string|null  $fact  the answer as a general fact of the event, null when it only concerns this visitor
     */
    public function __construct(
        public readonly array $messages,
        public readonly ?string $fact,
    ) {}
}
