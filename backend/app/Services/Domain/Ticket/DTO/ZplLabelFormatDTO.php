<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Ticket\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class ZplLabelFormatDTO extends BaseDataObject
{
    public const SUPPORTED_DPI = [203, 300, 600];

    public const LAYOUT_REFERENCE_DPI = 203;

    private const MM_PER_INCH = 25.4;

    public function __construct(
        public readonly int $dpi = 203,
        public readonly float $width_mm = 80.0,
        public readonly float $length_mm = 101.0,
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

    private function millimetresToDots(float $millimetres): int
    {
        return (int) round($millimetres * $this->dpi / self::MM_PER_INCH);
    }
}
