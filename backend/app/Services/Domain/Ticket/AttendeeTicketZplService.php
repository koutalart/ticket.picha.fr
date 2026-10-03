<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Ticket;

use HiEvents\Services\Domain\Ticket\DTO\TicketDataDTO;

class AttendeeTicketZplService
{
    public function __construct(
        private readonly TicketLayoutBuilder $layoutBuilder,
        private readonly ZplTicketRenderer $renderer,
    ) {}

    public function generate(TicketDataDTO $ticket): string
    {
        return $this->renderer->render($this->layoutBuilder->build($ticket));
    }
}
