<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Ticket;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use HiEvents\Services\Domain\Ticket\DTO\ZplGraphicDTO;
use HiEvents\Services\Domain\Ticket\Layout\TicketBoxElement;
use HiEvents\Services\Domain\Ticket\Layout\TicketGraphicElement;
use HiEvents\Services\Domain\Ticket\Layout\TicketLayout;
use HiEvents\Services\Domain\Ticket\Layout\TicketQrElement;
use HiEvents\Services\Domain\Ticket\Layout\TicketTextElement;
use Imagick;
use ImagickDraw;
use ImagickPixel;

/**
 * Draws the same layout as the Zebra label, so the e-mailed PDF and the on-screen ticket
 * match what the box office prints.
 */
class PngTicketRenderer
{
    private const FONT = 'vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf';

    private const EM_PER_HEIGHT = 1.02;

    private const BASELINE_PER_HEIGHT = 0.745;

    private const WIDTH_FACTOR = 0.72;

    private const QR_TOP_OFFSET = 10;

    public function render(TicketLayout $layout, int $scale = 2): string
    {
        $canvas = new Imagick;
        $canvas->newImage($layout->width * $scale, $layout->height * $scale, new ImagickPixel('white'));
        $canvas->setImageFormat('png');

        foreach ($layout->elements as $element) {
            match (true) {
                $element instanceof TicketTextElement => $this->text($canvas, $element, $scale),
                $element instanceof TicketBoxElement => $this->box($canvas, $element, $scale),
                $element instanceof TicketGraphicElement => $this->graphic($canvas, $element->graphic, $element->x, $element->y, $scale),
                $element instanceof TicketQrElement => $this->qr($canvas, $element, $scale),
            };
        }

        $canvas->setImageType(Imagick::IMGTYPE_GRAYSCALE);
        $png = $canvas->getImageBlob();
        $canvas->clear();

        return $png;
    }

    private function text(Imagick $canvas, TicketTextElement $text, int $scale): void
    {
        if ($text->text === '') {
            return;
        }

        $draw = $this->fontDraw($text, $scale);
        $lines = $text->maxLines > 1 && $text->blockWidth !== null
            ? $this->wrap($canvas, $draw, $text, $scale)
            : [$text->text];

        foreach ($lines as $index => $line) {
            $this->line($canvas, $draw, $text, $line, $text->y + $index * $text->height, $scale);
        }
    }

    private function fontDraw(TicketTextElement $text, int $scale): ImagickDraw
    {
        $draw = new ImagickDraw;
        $font = base_path(self::FONT);
        if (is_readable($font)) {
            $draw->setFont($font);
        }
        $draw->setFontSize(self::EM_PER_HEIGHT * $text->height * $scale);
        $draw->setFillColor(new ImagickPixel('black'));
        $draw->setTextAntialias(true);

        return $draw;
    }

    private function scaledWidth(Imagick $canvas, ImagickDraw $draw, TicketTextElement $text, string $content): float
    {
        return $canvas->queryFontMetrics($draw, $content)['textWidth'] * self::WIDTH_FACTOR * ($text->width / $text->height);
    }

    /**
     * @return list<string>
     */
    private function wrap(Imagick $canvas, ImagickDraw $draw, TicketTextElement $text, int $scale): array
    {
        $limit = $text->blockWidth * $scale;
        $lines = [''];
        foreach (preg_split('/\s+/u', $text->text) ?: [] as $word) {
            $current = array_key_last($lines);
            $candidate = trim($lines[$current].' '.$word);
            if ($lines[$current] === '' || $this->scaledWidth($canvas, $draw, $text, $candidate) <= $limit) {
                $lines[$current] = $candidate;
            } elseif (count($lines) < $text->maxLines) {
                $lines[] = $word;
            } else {
                $lines[$current] = $candidate;
            }
        }

        return $lines;
    }

    private function line(Imagick $canvas, ImagickDraw $draw, TicketTextElement $text, string $content, int $y, int $scale): void
    {
        $metrics = $canvas->queryFontMetrics($draw, $content);
        $naturalWidth = max(1, (int) ceil($metrics['textWidth']));
        $ascender = (int) ceil($metrics['ascender']);

        $glyphs = new Imagick;
        $glyphs->newImage($naturalWidth + 4, (int) ceil($metrics['textHeight']) + 4, new ImagickPixel('transparent'));
        $glyphs->annotateImage($draw, 2, $ascender + 2, 0, $content);

        $targetWidth = $naturalWidth * self::WIDTH_FACTOR * ($text->width / $text->height);
        if ($text->blockWidth !== null) {
            $targetWidth = min($targetWidth, $text->blockWidth * $scale);
        }
        $targetWidth = max(1, (int) round($targetWidth));
        $glyphs->resizeImage($targetWidth, $glyphs->getImageHeight(), Imagick::FILTER_LANCZOS, 1);

        $x = $text->x * $scale;
        if ($text->blockWidth !== null && $text->align !== 'L') {
            $free = $text->blockWidth * $scale - $targetWidth;
            $x += $text->align === 'C' ? intdiv($free, 2) : $free;
        }
        $top = (int) round(($y + self::BASELINE_PER_HEIGHT * $text->height) * $scale) - $ascender - 2;

        $canvas->compositeImage($glyphs, Imagick::COMPOSITE_OVER, $x, $top);
        $glyphs->clear();
    }

    private function box(Imagick $canvas, TicketBoxElement $box, int $scale): void
    {
        $draw = new ImagickDraw;
        $x0 = $box->x * $scale;
        $y0 = $box->y * $scale;
        $x1 = ($box->x + $box->width) * $scale - 1;
        $y1 = ($box->y + $box->height) * $scale - 1;
        $filled = $box->thickness * 2 >= min($box->width, $box->height);

        if ($filled) {
            $draw->setFillColor(new ImagickPixel('black'));
            $radius = $box->rounding > 0 ? min($box->width, $box->height) * $scale / 2 : 0;
            $radius > 0
                ? $draw->roundRectangle($x0, $y0, $x1, $y1, $radius, $radius)
                : $draw->rectangle($x0, $y0, $x1, $y1);
        } else {
            $half = $box->thickness * $scale / 2;
            $draw->setFillOpacity(0);
            $draw->setStrokeColor(new ImagickPixel('black'));
            $draw->setStrokeWidth($box->thickness * $scale);
            $draw->rectangle($x0 + $half, $y0 + $half, $x1 - $half, $y1 - $half);
        }

        $canvas->drawImage($draw);
    }

    private function graphic(Imagick $canvas, ZplGraphicDTO $graphic, int $x, int $y, int $scale): void
    {
        $bitmap = new Imagick;
        $bitmap->readImageBlob(sprintf("P4\n%d %d\n", $graphic->bytesPerRow * 8, $graphic->height).hex2bin($graphic->hex));
        $bitmap->cropImage($graphic->width, $graphic->height, 0, 0);
        $bitmap->scaleImage($graphic->width * $scale, $graphic->height * $scale);

        $canvas->compositeImage($bitmap, Imagick::COMPOSITE_MULTIPLY, $x * $scale, $y * $scale);
        $bitmap->clear();
    }

    private function qr(Imagick $canvas, TicketQrElement $qr, int $scale): void
    {
        $matrix = Encoder::encode($qr->data, ErrorCorrectionLevel::Q())->getMatrix();
        $module = $qr->magnification * $scale;
        $originX = $qr->x * $scale;
        $originY = ($qr->y + self::QR_TOP_OFFSET) * $scale;

        $draw = new ImagickDraw;
        $draw->setFillColor(new ImagickPixel('black'));
        for ($row = 0; $row < $matrix->getHeight(); $row++) {
            for ($column = 0; $column < $matrix->getWidth(); $column++) {
                if ($matrix->get($column, $row) === 1) {
                    $left = $originX + $column * $module;
                    $top = $originY + $row * $module;
                    $draw->rectangle($left, $top, $left + $module - 1, $top + $module - 1);
                }
            }
        }

        $canvas->drawImage($draw);
    }
}
