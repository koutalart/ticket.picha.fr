<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Event\Presentation\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class PosterMediaDTO extends BaseDataObject
{
    public function __construct(
        public readonly string $accent,
        public readonly string $banner_path,
    ) {}
}
