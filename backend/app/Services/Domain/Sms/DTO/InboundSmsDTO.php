<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class InboundSmsDTO extends BaseDataObject
{
    public function __construct(
        public readonly string $providerMessageId,
        public readonly string $contact,
        public readonly string $content,
        public readonly ?string $timestamp = null,
    ) {}
}
