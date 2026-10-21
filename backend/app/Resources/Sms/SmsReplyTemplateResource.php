<?php

declare(strict_types=1);

namespace HiEvents\Resources\Sms;

use HiEvents\DomainObjects\SmsReplyTemplateDomainObject;
use HiEvents\Resources\BaseResource;

/**
 * @mixin SmsReplyTemplateDomainObject
 */
class SmsReplyTemplateResource extends BaseResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->getId(),
            'name' => $this->getName(),
            'content' => $this->getContent(),
            'updated_at' => $this->getUpdatedAt(),
        ];
    }
}
