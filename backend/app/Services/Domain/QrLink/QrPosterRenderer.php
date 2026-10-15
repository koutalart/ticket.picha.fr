<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\QrLink;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use HiEvents\DomainObjects\Enums\QrPosterTemplate;
use HiEvents\Services\Domain\QrLink\DTO\QrPosterLogoDTO;
use Imagick;
use ImagickDraw;
use ImagickPixel;

class QrPosterRenderer
{
    private const FONT = 'vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf';

    private const DEFAULT_FOREGROUND = '#1c1530';

    private const DEFAULT_CARD = '#ffffff';

    private const MAX_CUSTOM_WIDTH = 2160;

    /**
     * Shared with the dashboard preview (frontend QrPosterPreview): keep both in sync.
     */
    public const PRESETS = [
        'story' => ['width' => 1080, 'height' => 1920, 'qr_center' => 0.56, 'qr_scale' => 0.6],
        'poster' => ['width' => 1240, 'height' => 1754, 'qr_center' => 0.6, 'qr_scale' => 0.5],
        'square' => ['width' => 1080, 'height' => 1080, 'qr_center' => 0.5, 'qr_scale' => 0.5],
    ];

    public function render(
        string $url,
        array $design,
        ?string $title,
        ?string $backgroundBlob = null,
        ?QrPosterLogoDTO $logo = null,
    ): string {
        $template = QrPosterTemplate::tryFrom($design['template'] ?? '') ?? QrPosterTemplate::NONE;
        $foreground = $design['foreground'] ?? self::DEFAULT_FOREGROUND;

        if ($template === QrPosterTemplate::CUSTOM && $backgroundBlob !== null) {
            $canvas = $this->customBackground($backgroundBlob);
            $qrCenter = ($design['qr_position'] ?? 50) / 100;
            $qrScale = ($design['qr_scale'] ?? 45) / 100;
            $title = null;
            $headerTitle = $this->posterTitle($design);
            $captionColor = $design['caption_color'] ?? '#ffffff';
        } else {
            $preset = self::PRESETS[$template->value] ?? self::PRESETS['story'];
            $canvas = $this->presetBackground($template, $preset['width'], $preset['height'], $foreground);
            $qrCenter = $preset['qr_center'];
            $qrScale = $preset['qr_scale'];
            $captionColor = $design['caption_color'] ?? ($template === QrPosterTemplate::POSTER ? $foreground : '#ffffff');
            $title = $this->posterTitle($design) ?? $title;
            $headerTitle = null;
            $logo = null;
        }

        $width = $canvas->getImageWidth();
        $height = $canvas->getImageHeight();
        $cardSize = (int) round($width * $qrScale);
        $cardTop = (int) round($height * $qrCenter - $cardSize / 2);
        $cardLeft = (int) round(($width - $cardSize) / 2);

        if ($title) {
            $this->title($canvas, $template, $title);
        }

        if ($headerTitle !== null || $logo !== null) {
            $this->header($canvas, $cardTop, $headerTitle, $logo, $captionColor);
        }

        $this->qrCard(
            $canvas,
            $url,
            $cardLeft,
            $cardTop,
            $cardSize,
            $foreground,
            $design['background'] ?? self::DEFAULT_CARD,
            $template === QrPosterTemplate::POSTER ? '#e4e1ec' : null,
        );

        $caption = trim((string) ($design['caption'] ?? ''));
        if ($caption !== '') {
            $fontSize = $width * 0.055;
            $this->centeredText($canvas, $caption, $cardTop + $cardSize + $fontSize * 1.6, $fontSize, $captionColor, $width * 0.9, 1);
        }

        $canvas->setImageFormat('png');
        $blob = $canvas->getImageBlob();
        $canvas->clear();

        return $blob;
    }

    private function posterTitle(array $design): ?string
    {
        $title = trim((string) ($design['poster_title'] ?? ''));

        return $title === '' ? null : $title;
    }

    /**
     * Logo and title stacked above the QR card. Mirrored by the dashboard preview (QrPosterPreview).
     */
    private function header(Imagick $canvas, int $cardTop, ?string $title, ?QrPosterLogoDTO $logo, string $color): void
    {
        $width = $canvas->getImageWidth();
        $titleSize = $width * (mb_strlen((string) $title) > 16 ? 0.068 : 0.085);
        $baseline = $cardTop - $width * 0.07;

        if ($title !== null) {
            $this->centeredText($canvas, $title, $baseline, $titleSize, $color, $width * 0.9, 1);
        }

        if ($logo === null) {
            return;
        }

        $image = $logo->isIcon ? $this->iconImage($logo->blob, $width, $color) : $this->badgeImage($logo->blob, $width);
        $logoBottom = $title !== null ? $baseline - $titleSize - $width * 0.04 : $cardTop - $width * 0.06;
        $canvas->compositeImage(
            $image,
            Imagick::COMPOSITE_OVER,
            (int) round(($width - $image->getImageWidth()) / 2),
            (int) round($logoBottom - $image->getImageHeight()),
        );
    }

    private function iconImage(string $svg, int $width, string $color): Imagick
    {
        $size = (int) round($width * 0.15);
        $icon = new Imagick;
        $icon->setBackgroundColor(new ImagickPixel('transparent'));
        $icon->setResolution(1200, 1200);
        $icon->readImageBlob(str_replace('currentColor', $color, $svg));
        $icon->setImageFormat('png');
        $icon->resizeImage($size, $size, Imagick::FILTER_LANCZOS, 1, true);

        return $icon;
    }

    private function badgeImage(string $blob, int $width): Imagick
    {
        $size = (int) round($width * 0.22);
        $badge = new Imagick;
        $badge->newImage($size, $size, new ImagickPixel('transparent'));
        $circle = new ImagickDraw;
        $circle->setFillColor(new ImagickPixel('#ffffff'));
        $circle->circle($size / 2, $size / 2, $size / 2, 1);
        $badge->drawImage($circle);

        $logo = new Imagick;
        $logo->readImageBlob($blob);
        $logo->setIteratorIndex(0);
        $logo = $logo->getImage();
        $inner = (int) round($size * 0.86);
        $logo->resizeImage($inner, $inner, Imagick::FILTER_LANCZOS, 1, true);
        $badge->compositeImage(
            $logo,
            Imagick::COMPOSITE_OVER,
            (int) round(($size - $logo->getImageWidth()) / 2),
            (int) round(($size - $logo->getImageHeight()) / 2),
        );
        $badge->setImageFormat('png');

        return $badge;
    }

    private function customBackground(string $blob): Imagick
    {
        $canvas = new Imagick;
        $canvas->readImageBlob($blob);
        $canvas->setIteratorIndex(0);
        $canvas = $canvas->getImage();
        $canvas->setImageColorspace(Imagick::COLORSPACE_SRGB);

        if ($canvas->getImageWidth() > self::MAX_CUSTOM_WIDTH) {
            $canvas->resizeImage(self::MAX_CUSTOM_WIDTH, 0, Imagick::FILTER_LANCZOS, 1);
        }

        return $canvas;
    }

    private function presetBackground(QrPosterTemplate $template, int $width, int $height, string $foreground): Imagick
    {
        $canvas = new Imagick;

        if ($template === QrPosterTemplate::STORY) {
            $canvas->newPseudoImage($width, $height, sprintf('gradient:%s-%s', $foreground, $this->darken($foreground, 0.55)));

            return $canvas;
        }

        if ($template === QrPosterTemplate::POSTER) {
            $canvas->newImage($width, $height, new ImagickPixel('#ffffff'));
            $band = new ImagickDraw;
            $band->setFillColor(new ImagickPixel($foreground));
            $band->rectangle(0, 0, $width, (int) round($height * 0.3));
            $canvas->drawImage($band);

            return $canvas;
        }

        $canvas->newImage($width, $height, new ImagickPixel($foreground));

        return $canvas;
    }

    private function title(Imagick $canvas, QrPosterTemplate $template, string $title): void
    {
        $width = $canvas->getImageWidth();
        $height = $canvas->getImageHeight();

        [$centerY, $fontSize, $maxLines] = match ($template) {
            QrPosterTemplate::POSTER => [$height * 0.15, $width * 0.07, 2],
            QrPosterTemplate::SQUARE => [$height * 0.12, $width * 0.05, 2],
            default => [$height * 0.19, $width * 0.075, 3],
        };

        $this->centeredText($canvas, $title, $centerY, $fontSize, '#ffffff', $width * 0.84, $maxLines, true);
    }

    private function qrCard(
        Imagick $canvas,
        string $url,
        int $left,
        int $top,
        int $size,
        string $foreground,
        string $cardColor,
        ?string $borderColor,
    ): void {
        $card = new ImagickDraw;
        $card->setFillColor(new ImagickPixel($cardColor));
        if ($borderColor !== null) {
            $card->setStrokeColor(new ImagickPixel($borderColor));
            $card->setStrokeWidth(max(2, $size * 0.006));
        }
        $card->roundRectangle($left, $top, $left + $size, $top + $size, $size * 0.06, $size * 0.06);
        $canvas->drawImage($card);

        $matrix = Encoder::encode($url, ErrorCorrectionLevel::M())->getMatrix();
        $modules = $matrix->getWidth();
        $padding = $size * 0.08;
        $module = (int) floor(($size - 2 * $padding) / $modules);
        $offset = (int) round(($size - $module * $modules) / 2);

        $draw = new ImagickDraw;
        $draw->setFillColor(new ImagickPixel($foreground));
        for ($row = 0; $row < $modules; $row++) {
            for ($column = 0; $column < $modules; $column++) {
                if ($matrix->get($column, $row) === 1) {
                    $x = $left + $offset + $column * $module;
                    $y = $top + $offset + $row * $module;
                    $draw->rectangle($x, $y, $x + $module - 1, $y + $module - 1);
                }
            }
        }
        $canvas->drawImage($draw);
    }

    private function centeredText(
        Imagick $canvas,
        string $text,
        float $centerY,
        float $fontSize,
        string $color,
        float $maxWidth,
        int $maxLines,
        bool $verticallyCentered = false,
    ): void {
        $draw = new ImagickDraw;
        $font = base_path(self::FONT);
        if (is_readable($font)) {
            $draw->setFont($font);
        }
        $draw->setFontSize($fontSize);
        $draw->setFillColor(new ImagickPixel($color));
        $draw->setTextAlignment(Imagick::ALIGN_CENTER);
        $draw->setTextAntialias(true);

        $lines = $this->wrap($canvas, $draw, $text, $maxWidth, $maxLines);
        $lineHeight = $fontSize * 1.25;
        $firstBaseline = $verticallyCentered
            ? $centerY - ($lineHeight * (count($lines) - 1)) / 2 + $fontSize * 0.35
            : $centerY;

        foreach ($lines as $index => $line) {
            $canvas->annotateImage($draw, $canvas->getImageWidth() / 2, $firstBaseline + $index * $lineHeight, 0, $line);
        }
    }

    /**
     * @return string[]
     */
    private function wrap(Imagick $canvas, ImagickDraw $draw, string $text, float $maxWidth, int $maxLines): array
    {
        $lines = [];
        $current = '';

        foreach (preg_split('/\s+/u', trim($text)) as $word) {
            $candidate = $current === '' ? $word : $current.' '.$word;
            if ($current !== '' && $canvas->queryFontMetrics($draw, $candidate)['textWidth'] > $maxWidth) {
                $lines[] = $current;
                $current = $word;
            } else {
                $current = $candidate;
            }
        }
        $lines[] = $current;

        if (count($lines) > $maxLines) {
            $lines = array_slice($lines, 0, $maxLines);
            $lines[$maxLines - 1] = rtrim($lines[$maxLines - 1], '.,;: ').'…';
        }

        return $lines;
    }

    private function darken(string $hex, float $factor): string
    {
        $hex = ltrim($hex, '#');
        $channels = array_map(static fn ($pair) => (int) round(hexdec($pair) * $factor), str_split($hex, 2));

        return vsprintf('#%02x%02x%02x', $channels);
    }
}
