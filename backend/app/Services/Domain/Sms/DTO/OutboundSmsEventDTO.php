<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\DomainObjects\Status\SmsMessageStatus;

class OutboundSmsEventDTO extends BaseDataObject
{
    public function __construct(
        public readonly SmsMessageStatus $status,
        public readonly string $providerMessageId,
        public readonly string $contact,
        public readonly ?string $content = null,
        public readonly ?string $requestId = null,
        public readonly ?string $errorMessage = null,
        public readonly ?string $timestamp = null,
    ) {}
}
