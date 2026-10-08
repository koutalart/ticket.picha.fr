<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Ticket\Layout;

class TicketLayout
{
    /**
     * @param  list<TicketTextElement|TicketBoxElement|TicketGraphicElement|TicketQrElement>  $elements
     */
    public function __construct(
        public readonly int $width,
        public readonly int $height,
        public readonly array $elements,
    ) {}
}
