<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Ticket;

use HiEvents\Services\Domain\Ticket\Layout\TicketBoxElement;
use HiEvents\Services\Domain\Ticket\Layout\TicketGraphicElement;
use HiEvents\Services\Domain\Ticket\Layout\TicketLayout;
use HiEvents\Services\Domain\Ticket\Layout\TicketQrElement;
use HiEvents\Services\Domain\Ticket\Layout\TicketTextElement;

class ZplTicketRenderer
{
    public function render(TicketLayout $layout): string
    {
        $lines = [
            '^XA',
            '^CI28',
            '^PW'.$layout->width,
            '^LL'.$layout->height,
            '^LH0,0',
            '^LT0',
            '^FWN',
        ];

        foreach ($layout->elements as $element) {
            $lines[] = match (true) {
                $element instanceof TicketTextElement => $this->text($element),
                $element instanceof TicketBoxElement => $this->box($element),
                $element instanceof TicketGraphicElement => $element->graphic->toZpl($element->x, $element->y),
                $element instanceof TicketQrElement => sprintf(
                    '^FO%d,%d^BQN,2,%d^FDQA,%s^FS',
                    $element->x,
                    $element->y,
                    $element->magnification,
                    $element->data,
                ),
            };
        }

        $lines[] = '^XZ';

        return implode("\n", $lines);
    }

    private function text(TicketTextElement $text): string
    {
        $origin = sprintf('^FO%d,%d^A0N,%d,%d', $text->x, $text->y, $text->height, $text->width);

        if ($text->blockWidth === null) {
            return $origin.'^FD'.$text->text.'^FS';
        }

        return sprintf('%s^FB%d,%d,0,%s^FD%s\\&^FS', $origin, $text->blockWidth, $text->maxLines, $text->align, $text->text);
    }

    private function box(TicketBoxElement $box): string
    {
        $shape = sprintf('^GB%d,%d,%d', $box->width, $box->height, $box->thickness);
        if ($box->rounding > 0) {
            $shape .= ',B,'.$box->rounding;
        }

        return sprintf('^FO%d,%d%s^FS', $box->x, $box->y, $shape);
    }
}
