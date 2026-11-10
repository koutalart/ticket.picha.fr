<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Ticket;

use HiEvents\Services\Domain\Ticket\DTO\TicketDataDTO;
use HiEvents\Services\Domain\Ticket\DTO\TicketImageDTO;

class TicketImageService
{
    private const LABEL_SCALE = 2;

    public function __construct(
        private readonly TicketLayoutBuilder $layoutBuilder,
        private readonly PngTicketRenderer $renderer,
        private readonly TicketCardRenderer $cardRenderer,
    ) {}

    public function image(TicketDataDTO $ticket): TicketImageDTO
    {
        if ($ticket->cardDesign !== null) {
            $card = $this->cardRenderer->render($ticket, $ticket->cardDesign);
            if ($card !== null) {
                return $card;
            }
        }

        $layout = $this->layoutBuilder->build($ticket);

        return new TicketImageDTO(
            content: $this->renderer->render($layout, self::LABEL_SCALE),
            mimeType: 'image/png',
            width: $layout->width * self::LABEL_SCALE,
            height: $layout->height * self::LABEL_SCALE,
        );
    }
}
