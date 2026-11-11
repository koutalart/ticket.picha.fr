<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Assistant\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class AssistantConfirmationRequestDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $actionId,
        public readonly string $token,
        public readonly string $expiresAt,
    ) {}
}
