<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Account\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\DomainObjects\AccountDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\UserDomainObject;

class ManagedOrganizationDTO extends BaseDataObject
{
    public function __construct(
        public readonly AccountDomainObject $account,
        public readonly UserDomainObject $user,
        public readonly OrganizerDomainObject $organizer,
    ) {}
}
