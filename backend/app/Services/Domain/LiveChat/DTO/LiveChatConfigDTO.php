<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\LiveChat\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class LiveChatConfigDTO extends BaseDataObject
{
    public function __construct(
        public readonly bool $enabled,
        public readonly ?string $organizerName = null,
        public readonly ?string $relayLine = null,
        public readonly int $maxMessageLength = 0,
    ) {}
}
