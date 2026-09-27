<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Ticket;

use GdImage;
use HiEvents\Services\Domain\Ticket\DTO\ZplGraphicDTO;

class ZplImageConverter
{
    private const BLACK_THRESHOLD = 140;

    public function convert(string $imageBytes, int $maxWidth, int $maxHeight): ?ZplGraphicDTO
    {
        $source = @imagecreatefromstring($imageBytes);
        if (! $source instanceof GdImage) {
            return null;
        }

        $source = $this->trimBlankMargins($this->flattenOnWhite($source));

        $ratio = min($maxWidth / imagesx($source), $maxHeight / imagesy($source));
        $width = max(1, (int) round(imagesx($source) * $ratio));
        $height = max(1, (int) round(imagesy($source) * $ratio));

        $canvas = imagecreatetruecolor($width, $height);
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source));

        $bytesPerRow = intdiv($width + 7, 8);
        $hex = '';
        for ($y = 0; $y < $height; $y++) {
            $bits = '';
            for ($x = 0; $x < $width; $x++) {
                $rgb = imagecolorat($canvas, $x, $y);
                $luminance = 0.299 * (($rgb >> 16) & 0xFF) + 0.587 * (($rgb >> 8) & 0xFF) + 0.114 * ($rgb & 0xFF);
                $bits .= $luminance < self::BLACK_THRESHOLD ? '1' : '0';
            }
            foreach (str_split(str_pad($bits, $bytesPerRow * 8, '0'), 8) as $byte) {
                $hex .= sprintf('%02X', bindec($byte));
            }
        }

        $total = $bytesPerRow * $height;

        return new ZplGraphicDTO(
            zpl: '^GFA,'.$total.','.$total.','.$bytesPerRow.','.$hex,
            width: $width,
            height: $height,
        );
    }

    private function flattenOnWhite(GdImage $source): GdImage
    {
        $flat = imagecreatetruecolor(imagesx($source), imagesy($source));
        imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
        imagealphablending($flat, true);
        imagecopy($flat, $source, 0, 0, 0, 0, imagesx($source), imagesy($source));

        return $flat;
    }

    private function trimBlankMargins(GdImage $image): GdImage
    {
        $trimmed = imagecropauto($image, IMG_CROP_THRESHOLD, 0.1, 0xFFFFFF);

        return $trimmed instanceof GdImage ? $trimmed : $image;
    }
}
