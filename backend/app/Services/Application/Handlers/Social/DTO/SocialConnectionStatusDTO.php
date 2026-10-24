<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Social\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\DomainObjects\OrganizerSocialConnectionDomainObject;

class SocialConnectionStatusDTO extends BaseDataObject
{
    public function __construct(
        public readonly bool $available,
        public readonly ?OrganizerSocialConnectionDomainObject $connection,
    ) {}
}
