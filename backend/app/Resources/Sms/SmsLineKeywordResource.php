<?php

declare(strict_types=1);

namespace HiEvents\Resources\Sms;

use HiEvents\DomainObjects\SmsLineKeywordDomainObject;
use HiEvents\Resources\BaseResource;

/**
 * @mixin SmsLineKeywordDomainObject
 */
class SmsLineKeywordResource extends BaseResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->getId(),
            'keyword' => $this->getKeyword(),
            'is_active' => $this->getIsActive(),
            'welcome_message' => $this->getWelcomeMessage(),
            'created_at' => $this->getCreatedAt(),
            'updated_at' => $this->getUpdatedAt(),
        ];
    }
}
