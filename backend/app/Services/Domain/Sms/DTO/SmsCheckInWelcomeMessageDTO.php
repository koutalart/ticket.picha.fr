<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class SmsCheckInWelcomeMessageDTO extends BaseDataObject
{
    public function __construct(
        public readonly string $content,
        public readonly string $source,
    ) {}
}
