<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Ticket;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;

class QrCodePngRenderer
{
    private const QUIET_ZONE_MODULES = 2;

    public function render(string $data, int $sizePixels): string
    {
        $matrix = Encoder::encode($data, ErrorCorrectionLevel::Q())->getMatrix();
        $modules = $matrix->getWidth();
        $moduleSize = max(1, intdiv($sizePixels, $modules + 2 * self::QUIET_ZONE_MODULES));
        $origin = intdiv($sizePixels - $modules * $moduleSize, 2);

        $image = imagecreatetruecolor($sizePixels, $sizePixels);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        $black = imagecolorallocate($image, 0, 0, 0);

        for ($y = 0; $y < $modules; $y++) {
            for ($x = 0; $x < $modules; $x++) {
                if ($matrix->get($x, $y) === 1) {
                    imagefilledrectangle(
                        $image,
                        $origin + $x * $moduleSize,
                        $origin + $y * $moduleSize,
                        $origin + ($x + 1) * $moduleSize - 1,
                        $origin + ($y + 1) * $moduleSize - 1,
                        $black,
                    );
                }
            }
        }

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
