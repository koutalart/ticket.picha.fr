<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\DomainObjects\Enums\SmsMessageAuthorType;

class SmsAssistantThreadMessageDTO extends BaseDataObject
{
    public function __construct(
        public readonly SmsMessageAuthorType $author,
        public readonly string $content,
        public readonly ?string $sentAt,
    ) {}
}
