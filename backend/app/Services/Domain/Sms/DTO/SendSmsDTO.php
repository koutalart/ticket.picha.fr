<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\DomainObjects\Enums\SmsAutoReplyType;
use HiEvents\DomainObjects\Enums\SmsMessageAuthorType;

class SendSmsDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $organizerId,
        public readonly string $phone,
        public readonly string $content,
        public readonly SmsMessageAuthorType $authorType = SmsMessageAuthorType::HUMAN,
        public readonly ?int $sentByUserId = null,
        public readonly ?int $autoReplyToMessageId = null,
        public readonly ?SmsAutoReplyType $autoReplyType = null,
        public readonly bool $keepDraft = false,
    ) {}
}
