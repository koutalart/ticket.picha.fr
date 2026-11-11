<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Assistant\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class AssistantActionContextDTO extends BaseDataObject
{
    public function __construct(
        public readonly string $command,
        public readonly int $level,
        public readonly string $requestedBy,
        public readonly array $params,
        public readonly ?int $accountId = null,
        public readonly ?int $organizerId = null,
        public readonly ?int $eventId = null,
    ) {}
}
