<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Ticket\Layout;

class TicketQrElement
{
    public function __construct(
        public readonly int $x,
        public readonly int $y,
        public readonly int $magnification,
        public readonly string $data,
    ) {}
}
