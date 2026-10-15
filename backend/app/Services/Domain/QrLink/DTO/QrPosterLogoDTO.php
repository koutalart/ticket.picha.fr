<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\QrLink\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class QrPosterLogoDTO extends BaseDataObject
{
    public function __construct(
        public string $blob,
        public bool $isIcon,
    ) {}
}
