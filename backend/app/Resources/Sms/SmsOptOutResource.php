<?php

declare(strict_types=1);

namespace HiEvents\Resources\Sms;

use HiEvents\DomainObjects\SmsOptOutDomainObject;
use HiEvents\Resources\BaseResource;

/**
 * @mixin SmsOptOutDomainObject
 */
class SmsOptOutResource extends BaseResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->getId(),
            'phone' => $this->getPhone(),
            'source' => $this->getSource(),
            'contact_name' => $this->getContactName(),
            'created_at' => $this->getCreatedAt(),
        ];
    }
}
