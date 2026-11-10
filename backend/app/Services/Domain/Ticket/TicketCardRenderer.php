<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Ticket;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use HiEvents\Services\Domain\Ticket\DTO\TicketCardDesignDTO;
use HiEvents\Services\Domain\Ticket\DTO\TicketDataDTO;
use HiEvents\Services\Domain\Ticket\DTO\TicketImageDTO;
use Illuminate\Filesystem\FilesystemManager;
use Imagick;
use ImagickDraw;
use ImagickPixel;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Fills the white box of an invitation card picture: the ticket QR on the left,
 * the guest name and the card lines on the right.
 */
class TicketCardRenderer
{
    private const FALLBACK_BOLD_FONT = 'vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf';

    private const FALLBACK_REGULAR_FONT = 'vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf';

    private const CACHE_DIRECTORY = 'app/ticket-card-cache';

    private const WHITE_THRESHOLD = 245;

    private const MIN_FRAME_HEIGHT = 80;

    private const PADDING = 24;

    private const QR_INSET = 8;

    private const SEPARATOR_GAP = 30;

    private const TEXT_GAP = 32;

    private const TEXT_MARGIN = 30;

    private const NAME_SIZE = 56;

    private const NAME_MIN_SIZE = 34;

    private const LINE_SIZE = 28;

    private const FOOTER_SIZE = 22;

    private const MIN_SIZE = 16;

    private const STATUS_COLOR = '#c0392b';

    private const JPEG_QUALITY = 90;

    public function __construct(
        private readonly FilesystemManager $filesystemManager,
        private readonly LoggerInterface $logger,
    ) {}

    public function render(TicketDataDTO $ticket, TicketCardDesignDTO $design): ?TicketImageDTO
    {
        $background = $this->localFile($design->disk, $design->backgroundPath);
        if ($background === null) {
            return null;
        }

        $canvas = new Imagick;
        $canvas->readImage($background);
        $canvas->setImageColorspace(Imagick::COLORSPACE_SRGB);
        $canvas->setImageBackgroundColor(new ImagickPixel('white'));
        $canvas->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);

        $frame = $design->frame ?? $this->detectFrame($canvas);
        if ($frame === null) {
            $this->logger->warning('Ticket card frame not found', ['path' => $design->backgroundPath]);
            $canvas->clear();

            return null;
        }

        $x0 = $frame['x'];
        $y0 = $frame['y'];
        $x1 = $frame['x'] + $frame['width'] - 1;
        $y1 = $frame['y'] + $frame['height'] - 1;
        $qrSize = ($y1 - $y0) - 2 * self::PADDING;
        $qrX = $x0 + self::PADDING + self::QR_INSET;

        $this->qr($canvas, $ticket->publicId, $qrX, $y0 + self::PADDING, $qrSize, $design->qrColor);

        $separatorX = $qrX + $qrSize + self::SEPARATOR_GAP;
        $separator = new ImagickDraw;
        $separator->setFillColor(new ImagickPixel($design->separatorColor));
        $separator->rectangle($separatorX - 1, $y0 + self::TEXT_MARGIN, $separatorX, $y1 - self::TEXT_MARGIN);
        $canvas->drawImage($separator);

        $textX = $separatorX + self::TEXT_GAP;
        $this->lines($canvas, $ticket, $design, $textX, $y0 + self::TEXT_MARGIN, $x1 - self::TEXT_MARGIN - $textX);

        $canvas->setImageFormat('jpeg');
        $canvas->setImageCompressionQuality(self::JPEG_QUALITY);
        $canvas->stripImage();
        $image = new TicketImageDTO(
            content: $canvas->getImageBlob(),
            mimeType: 'image/jpeg',
            width: $canvas->getImageWidth(),
            height: $canvas->getImageHeight(),
            isCard: true,
        );
        $canvas->clear();

        return $image;
    }

    /**
     * @return array{x: int, y: int, width: int, height: int}|null
     */
    public function detectFrame(Imagick $canvas): ?array
    {
        $width = $canvas->getImageWidth();
        $height = $canvas->getImageHeight();
        $centerX = intdiv($width, 2);
        $startY = intdiv($height, 2);

        $column = $canvas->exportImagePixels($centerX, $startY, 1, $height - $startY, 'RGB', Imagick::PIXEL_CHAR);
        [$top, $bottom] = $this->longestWhiteRun($column, 0, $height - $startY - 1);
        if ($top === null || $bottom - $top + 1 < self::MIN_FRAME_HEIGHT) {
            return null;
        }

        $top += $startY;
        $bottom += $startY;
        $row = $canvas->exportImagePixels(0, intdiv($top + $bottom, 2), $width, 1, 'RGB', Imagick::PIXEL_CHAR);
        $left = $centerX;
        while ($left > 0 && $this->isWhite($row, $left - 1)) {
            $left--;
        }
        $right = $centerX;
        while ($right < $width - 1 && $this->isWhite($row, $right + 1)) {
            $right++;
        }

        return ['x' => $left, 'y' => $top, 'width' => $right - $left + 1, 'height' => $bottom - $top + 1];
    }

    /**
     * @param  list<int>  $pixels
     * @return array{0: ?int, 1: ?int}
     */
    private function longestWhiteRun(array $pixels, int $from, int $to): array
    {
        $best = [null, null];
        $start = null;
        for ($index = $from; $index <= $to + 1; $index++) {
            $white = $index <= $to && $this->isWhite($pixels, $index);
            if ($white && $start === null) {
                $start = $index;
            } elseif (! $white && $start !== null) {
                if ($best[0] === null || $index - 1 - $start > $best[1] - $best[0]) {
                    $best = [$start, $index - 1];
                }
                $start = null;
            }
        }

        return $best;
    }

    /**
     * @param  list<int>  $pixels
     */
    private function isWhite(array $pixels, int $index): bool
    {
        return min($pixels[$index * 3], $pixels[$index * 3 + 1], $pixels[$index * 3 + 2]) > self::WHITE_THRESHOLD;
    }

    private function qr(Imagick $canvas, string $data, int $x, int $y, int $size, string $color): void
    {
        $matrix = Encoder::encode($data, ErrorCorrectionLevel::Q())->getMatrix();
        $modules = $matrix->getWidth();

        $draw = new ImagickDraw;
        $draw->setFillColor(new ImagickPixel($color));
        for ($row = 0; $row < $modules; $row++) {
            for ($column = 0; $column < $modules; $column++) {
                if ($matrix->get($column, $row) === 1) {
                    $draw->rectangle(
                        $x + intdiv($column * $size, $modules),
                        $y + intdiv($row * $size, $modules),
                        $x + intdiv(($column + 1) * $size, $modules) - 1,
                        $y + intdiv(($row + 1) * $size, $modules) - 1,
                    );
                }
            }
        }

        $canvas->drawImage($draw);
    }

    private function lines(Imagick $canvas, TicketDataDTO $ticket, TicketCardDesignDTO $design, int $x, int $y, int $maxWidth): void
    {
        $bold = $this->font($design->disk, $design->fontBoldPath, self::FALLBACK_BOLD_FONT);
        $regular = $this->font($design->disk, $design->fontRegularPath, self::FALLBACK_REGULAR_FONT);
        $light = $design->fontLightPath !== null
            ? $this->font($design->disk, $design->fontLightPath, $regular)
            : $regular;

        $nameSize = self::NAME_SIZE;
        while ($nameSize > self::NAME_MIN_SIZE && $this->textWidth($canvas, $ticket->attendeeName, $bold, $nameSize) > $maxWidth) {
            $nameSize -= 2;
        }

        $footer = [$design->footerLine, $light, self::FOOTER_SIZE, $design->mutedColor, 22];
        if ($ticket->status !== TicketDataDTO::STATUS_VALID) {
            $footer = [
                mb_strtoupper($ticket->status === TicketDataDTO::STATUS_CANCELLED ? __('Cancelled') : __('Awaiting payment')),
                $bold,
                self::FOOTER_SIZE,
                self::STATUS_COLOR,
                22,
            ];
        }

        $lines = [
            [$ticket->attendeeName, $bold, $nameSize, $design->textColor, 0],
            [$design->guestsLine, $regular, self::LINE_SIZE, $design->textColor, 14],
            [$design->dressCodeLine, $bold, self::LINE_SIZE, $design->accentColor, 22],
            $footer,
        ];

        foreach ($lines as [$text, $font, $size, $color, $gap]) {
            if ($text === '') {
                continue;
            }

            $y += $gap;
            while ($size > self::MIN_SIZE && $this->textWidth($canvas, $text, $font, $size) > $maxWidth) {
                $size--;
            }

            $draw = $this->draw($font, $size);
            $draw->setFillColor(new ImagickPixel($color));
            $ascender = $canvas->queryFontMetrics($draw, $text)['ascender'];
            $canvas->annotateImage($draw, $x, $y + $ascender, 0, $text);
            $y += $size;
        }
    }

    private function draw(string $font, int $size): ImagickDraw
    {
        $draw = new ImagickDraw;
        $draw->setResolution(72, 72);
        $draw->setFont($font);
        $draw->setFontSize($size);
        $draw->setTextAntialias(true);

        return $draw;
    }

    private function textWidth(Imagick $canvas, string $text, string $font, int $size): float
    {
        return (float) $canvas->queryFontMetrics($this->draw($font, $size), $text)['textWidth'];
    }

    private function font(string $disk, ?string $path, string $fallback): string
    {
        $local = $path !== null ? $this->localFile($disk, $path) : null;
        if ($local !== null) {
            return $local;
        }

        return str_starts_with($fallback, '/') ? $fallback : base_path($fallback);
    }

    private function localFile(string $disk, string $path): ?string
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $local = storage_path(self::CACHE_DIRECTORY.'/'.sha1($disk.'|'.$path).($extension !== '' ? '.'.$extension : ''));
        if (is_file($local)) {
            return $local;
        }

        try {
            $content = $this->filesystemManager->disk($disk)->get($path);
            if ($content === null || $content === '') {
                $this->logger->warning('Ticket card file is empty or missing', ['disk' => $disk, 'path' => $path]);

                return null;
            }

            if (! is_dir(dirname($local))) {
                mkdir(dirname($local), 0775, true);
            }
            $temporary = $local.'.'.getmypid().'.tmp';
            file_put_contents($temporary, $content);
            rename($temporary, $local);

            return $local;
        } catch (Throwable $exception) {
            $this->logger->warning('Could not read ticket card file', [
                'disk' => $disk,
                'path' => $path,
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }
    }
}
