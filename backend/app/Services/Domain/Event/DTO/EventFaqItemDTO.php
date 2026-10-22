<?php

namespace HiEvents\Services\Domain\Event\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class EventFaqItemDTO extends BaseDataObject
{
    public function __construct(
        public readonly string $question,
        public readonly string $answer,
    ) {}
}
