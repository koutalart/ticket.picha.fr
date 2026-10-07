<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Ticket;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\ByteMatrix;
use BaconQrCode\Encoder\Encoder;
use HiEvents\Services\Domain\Ticket\DTO\ZplQrCodeGraphicDTO;

class ZplQrCodeRenderer
{
    public function renderToFit(string $data, int $boxDots): ZplQrCodeGraphicDTO
    {
        $matrix = $this->matrix($data);

        return $this->draw($matrix, max(1, intdiv($boxDots, $matrix->getWidth())));
    }

    public function render(string $data, int $moduleDots): ZplQrCodeGraphicDTO
    {
        return $this->draw($this->matrix($data), max(1, $moduleDots));
    }

    private function matrix(string $data): ByteMatrix
    {
        return Encoder::encode($data, ErrorCorrectionLevel::Q())->getMatrix();
    }

    private function draw(ByteMatrix $matrix, int $moduleDots): ZplQrCodeGraphicDTO
    {
        $modules = $matrix->getWidth();
        $size = $modules * $moduleDots;
        $bytesPerRow = intdiv($size + 7, 8);

        $hex = '';
        for ($y = 0; $y < $modules; $y++) {
            $bits = '';
            for ($x = 0; $x < $modules; $x++) {
                $bits .= str_repeat($matrix->get($x, $y) === 1 ? '1' : '0', $moduleDots);
            }
            $bits = str_pad($bits, $bytesPerRow * 8, '0');

            $row = '';
            foreach (str_split($bits, 8) as $byte) {
                $row .= sprintf('%02X', bindec($byte));
            }
            $hex .= str_repeat($row, $moduleDots);
        }

        $total = $bytesPerRow * $size;

        return new ZplQrCodeGraphicDTO(
            zpl: '^GFA,'.$total.','.$total.','.$bytesPerRow.','.$hex,
            size: $size,
        );
    }
}
