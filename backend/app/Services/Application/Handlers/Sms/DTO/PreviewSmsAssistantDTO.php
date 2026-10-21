<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Sms\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class PreviewSmsAssistantDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $organizerId,
        public readonly string $customerMessage,
        public readonly ?string $instructions,
    ) {}
}
