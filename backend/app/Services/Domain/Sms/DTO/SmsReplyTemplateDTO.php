<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class SmsReplyTemplateDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $organizerId,
        public readonly string $name,
        public readonly string $content,
    ) {}
}
