<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Social\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class MetaOAuthCallbackResultDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $organizerId,
        public readonly string $selectionKey,
    ) {}
}
