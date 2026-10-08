<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Ticket;

use GdImage;
use HiEvents\Services\Domain\Ticket\DTO\ZplGraphicDTO;

class ZplImageConverter
{
    private const INK_THRESHOLD = 160;

    private const OPAQUE_ALPHA = 64;

    private const LIGHT_LOGO_RATIO = 0.8;

    public function convert(string $imageData, int $maxWidth, int $maxHeight): ?ZplGraphicDTO
    {
        $source = @imagecreatefromstring($imageData);
        if (! $source instanceof GdImage) {
            return null;
        }

        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        if ($sourceWidth < 1 || $sourceHeight < 1) {
            return null;
        }

        $scale = min($maxWidth / $sourceWidth, $maxHeight / $sourceHeight, 1.0);
        $width = max(1, (int) round($sourceWidth * $scale));
        $height = max(1, (int) round($sourceHeight * $scale));

        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 255, 255, 255, 127));
        imagecopyresampled($image, $source, 0, 0, 0, 0, $width, $height, $sourceWidth, $sourceHeight);

        [$luminance, $opaque] = $this->readPixels($image, $width, $height);
        $silhouette = $this->isLightLogoOnTransparency($luminance, $opaque);

        $bytesPerRow = intdiv($width + 7, 8);
        $hex = '';
        for ($y = 0; $y < $height; $y++) {
            $bits = '';
            for ($x = 0; $x < $width; $x++) {
                $index = $y * $width + $x;
                $ink = $silhouette
                    ? $opaque[$index]
                    : $opaque[$index] && $luminance[$index] < self::INK_THRESHOLD;
                $bits .= $ink ? '1' : '0';
            }
            $bits = str_pad($bits, $bytesPerRow * 8, '0');
            foreach (str_split($bits, 8) as $byte) {
                $hex .= sprintf('%02X', bindec($byte));
            }
        }

        return new ZplGraphicDTO(
            width: $width,
            height: $height,
            bytesPerRow: $bytesPerRow,
            hex: $hex,
        );
    }

    /**
     * @return array{0: list<int>, 1: list<bool>}
     */
    private function readPixels(GdImage $image, int $width, int $height): array
    {
        $luminance = [];
        $opaque = [];
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $rgba = imagecolorat($image, $x, $y);
                $alpha = ($rgba >> 24) & 0x7F;
                $red = ($rgba >> 16) & 0xFF;
                $green = ($rgba >> 8) & 0xFF;
                $blue = $rgba & 0xFF;
                $luminance[] = (int) round(0.299 * $red + 0.587 * $green + 0.114 * $blue);
                $opaque[] = $alpha < self::OPAQUE_ALPHA;
            }
        }

        return [$luminance, $opaque];
    }

    /**
     * @param  list<int>  $luminance
     * @param  list<bool>  $opaque
     */
    private function isLightLogoOnTransparency(array $luminance, array $opaque): bool
    {
        $opaqueCount = 0;
        $lightCount = 0;
        foreach ($opaque as $index => $isOpaque) {
            if (! $isOpaque) {
                continue;
            }
            $opaqueCount++;
            if ($luminance[$index] >= self::INK_THRESHOLD) {
                $lightCount++;
            }
        }

        $hasTransparency = $opaqueCount < count($opaque);

        return $hasTransparency
            && $opaqueCount > 0
            && $lightCount / $opaqueCount >= self::LIGHT_LOGO_RATIO;
    }
}
