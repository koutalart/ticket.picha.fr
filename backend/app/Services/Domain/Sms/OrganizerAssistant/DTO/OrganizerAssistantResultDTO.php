<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms\OrganizerAssistant\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class OrganizerAssistantResultDTO extends BaseDataObject
{
    public function __construct(
        public readonly string $reply,
        public readonly bool $needsHuman,
        public readonly ?string $summary,
    ) {}
}
