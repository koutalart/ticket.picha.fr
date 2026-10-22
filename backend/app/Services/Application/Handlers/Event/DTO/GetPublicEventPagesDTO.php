<?php

namespace HiEvents\Services\Application\Handlers\Event\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class GetPublicEventPagesDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $eventId,
        public readonly ?int $authenticatedAccountId = null,
        public readonly bool $isSuperAdmin = false,
    ) {}
}
