<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Ticket\Layout;

class TicketBoxElement
{
    public function __construct(
        public readonly int $x,
        public readonly int $y,
        public readonly int $width,
        public readonly int $height,
        public readonly int $thickness,
        public readonly int $rounding = 0,
    ) {}
}
