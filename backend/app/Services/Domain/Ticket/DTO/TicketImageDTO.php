<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Ticket\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class TicketImageDTO extends BaseDataObject
{
    public function __construct(
        public readonly string $content,
        public readonly string $mimeType,
        public readonly int $width,
        public readonly int $height,
        public readonly bool $isCard = false,
    ) {}
}
