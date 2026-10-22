<?php

namespace HiEvents\Services\Domain\Event\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class EventPageSectionDTO extends BaseDataObject
{
    public function __construct(
        public readonly string $title,
        public readonly ?string $content = null,
    ) {}
}
