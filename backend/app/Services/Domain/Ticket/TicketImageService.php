<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Ticket;

use HiEvents\Services\Domain\Ticket\DTO\TicketDataDTO;

class TicketImageService
{
    public function __construct(
        private readonly TicketLayoutBuilder $layoutBuilder,
        private readonly PngTicketRenderer $renderer,
    ) {}

    public function png(TicketDataDTO $ticket): string
    {
        return $this->renderer->render($this->layoutBuilder->build($ticket));
    }
}
