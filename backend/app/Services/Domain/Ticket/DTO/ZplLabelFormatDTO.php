<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Ticket\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class ZplLabelFormatDTO extends BaseDataObject
{
    public const SUPPORTED_DPI = [203, 300, 600];

    public const LAYOUT_REFERENCE_DPI = 203;

    public const LAYOUT_REFERENCE_WIDTH = 631;

    public const LAYOUT_REFERENCE_LENGTH = 695;

    private const MM_PER_INCH = 25.4;

    public function __construct(
        public readonly int $dpi = 203,
        public readonly float $width_mm = 79.0,
        public readonly float $length_mm = 84.0,
    ) {}

    public function widthDots(): int
    {
        return $this->millimetresToDots($this->width_mm);
    }

    public function lengthDots(): int
    {
        return $this->millimetresToDots($this->length_mm);
    }

    public function scale(int $referenceDots): int
    {
        return (int) round($referenceDots * $this->dpi / self::LAYOUT_REFERENCE_DPI);
    }

    public function fitRatio(): float
    {
        return min(
            1.0,
            $this->lengthDots() / ($this->dpiRatio() * self::LAYOUT_REFERENCE_LENGTH),
            $this->widthDots() / ($this->dpiRatio() * self::LAYOUT_REFERENCE_WIDTH),
        );
    }

    public function layout(int $referenceDots): int
    {
        return (int) round($referenceDots * $this->dpiRatio() * $this->fitRatio());
    }

    public function horizontalOffset(): int
    {
        return max(0, intdiv($this->widthDots() - $this->layout(self::LAYOUT_REFERENCE_WIDTH) + 1, 2));
    }

    private function dpiRatio(): float
    {
        return $this->dpi / self::LAYOUT_REFERENCE_DPI;
    }

    private function millimetresToDots(float $millimetres): int
    {
        return (int) round($millimetres * $this->dpi / self::MM_PER_INCH);
    }
}
