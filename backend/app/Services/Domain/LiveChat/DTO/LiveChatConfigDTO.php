<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\LiveChat\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\DomainObjects\Enums\LiveChatStyle;

class LiveChatConfigDTO extends BaseDataObject
{
    public function __construct(
        public readonly bool $enabled,
        public readonly ?string $organizerName = null,
        public readonly ?string $relayLine = null,
        public readonly int $maxMessageLength = 0,
        public readonly LiveChatStyle $style = LiveChatStyle::CLASSIC,
        public readonly ?string $avatarUrl = null,
    ) {}
}
