<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Email\Inbox\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class OutgoingMessageImportResultDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $imported,
        public readonly int $skipped,
        public readonly string $sentBefore,
    ) {}
}
