<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Sms\Team\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\DomainObjects\Enums\OrganizerTeamContactRole;

class SaveOrganizerTeamContactDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $organizerId,
        public readonly string $phone,
        public readonly ?string $name,
        public readonly OrganizerTeamContactRole $role,
    ) {}
}
