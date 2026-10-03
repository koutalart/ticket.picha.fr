<?php

declare(strict_types=1);

namespace HiEvents\Services\Infrastructure\Image\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class OptimizedImageDTO extends BaseDataObject
{
    public function __construct(
        public readonly string $contents,
        public readonly string $mimeType,
        public readonly string $extension,
        public readonly int $width,
        public readonly int $height,
    ) {}
}
