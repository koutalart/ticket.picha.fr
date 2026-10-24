<?php

declare(strict_types=1);

namespace HiEvents\Resources\Sms;

use HiEvents\DomainObjects\OrganizerTeamContactDomainObject;
use HiEvents\Resources\BaseResource;

/**
 * @mixin OrganizerTeamContactDomainObject
 */
class OrganizerTeamContactResource extends BaseResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->getId(),
            'phone' => $this->getPhone(),
            'name' => $this->getName(),
            'role' => $this->getRole(),
            'created_at' => $this->getCreatedAt(),
        ];
    }
}
