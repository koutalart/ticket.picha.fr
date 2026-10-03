<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Ticket\Layout;

class TicketTextElement
{
    public function __construct(
        public readonly int $x,
        public readonly int $y,
        public readonly int $height,
        public readonly int $width,
        public readonly string $text,
        public readonly ?int $blockWidth = null,
        public readonly string $align = 'L',
        public readonly int $maxLines = 1,
    ) {}
}
