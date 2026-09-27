<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Ticket\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class ZplGraphicDTO extends BaseDataObject
{
    public function __construct(
        public readonly string $zpl,
        public readonly int $width,
        public readonly int $height,
    ) {}
}
