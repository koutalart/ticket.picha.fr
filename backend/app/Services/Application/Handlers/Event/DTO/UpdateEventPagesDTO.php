<?php

namespace HiEvents\Services\Application\Handlers\Event\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\Services\Domain\Event\DTO\EventPagesDTO;

class UpdateEventPagesDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $eventId,
        public readonly EventPagesDTO $pages,
    ) {}
}
