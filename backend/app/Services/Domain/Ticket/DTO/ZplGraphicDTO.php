<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Ticket\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class ZplGraphicDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $width,
        public readonly int $height,
        public readonly int $bytesPerRow,
        public readonly string $hex,
    ) {}

    public function toZpl(int $x, int $y): string
    {
        $total = intdiv(strlen($this->hex), 2);

        return sprintf('^FO%d,%d^GFA,%d,%d,%d,%s^FS', $x, $y, $total, $total, $this->bytesPerRow, $this->hex);
    }
}
