<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\DomainObjects\Enums\SmsAssistantOutcome;

class SmsAssistantResultDTO extends BaseDataObject
{
    public function __construct(
        public readonly SmsAssistantOutcome $outcome,
        public readonly ?string $reply,
        public readonly ?string $reason,
        public readonly bool $autoSendSafe = false,
        public readonly ?string $holdingReply = null,
    ) {}
}
