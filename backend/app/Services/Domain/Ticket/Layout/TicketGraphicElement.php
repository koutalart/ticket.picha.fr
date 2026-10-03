<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Ticket\Layout;

use HiEvents\Services\Domain\Ticket\DTO\ZplGraphicDTO;

class TicketGraphicElement
{
    public function __construct(
        public readonly int $x,
        public readonly int $y,
        public readonly ZplGraphicDTO $graphic,
    ) {}
}
