<?php

namespace HiEvents\Services\Domain\Mail\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class EmailLogoDTO extends BaseDataObject
{
    public function __construct(
        public string $url,
        public ?string $alt = null,
    ) {}
}
