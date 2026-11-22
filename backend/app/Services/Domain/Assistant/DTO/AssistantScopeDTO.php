<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Assistant\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;

class AssistantScopeDTO extends BaseDataObject
{
    public function __construct(
        public readonly ?OrganizerDomainObject $organizer = null,
        public readonly ?EventDomainObject $event = null,
    ) {}

    public function eventId(): int
    {
        return $this->event->getId();
    }

    public function accountId(): int
    {
        return $this->organizer->getAccountId();
    }
}
