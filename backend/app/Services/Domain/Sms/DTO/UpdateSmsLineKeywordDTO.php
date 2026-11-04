<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class UpdateSmsLineKeywordDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $organizerId,
        public readonly int $keywordId,
        public readonly bool $isActive,
        public readonly ?string $welcomeMessage = null,
    ) {}
}
