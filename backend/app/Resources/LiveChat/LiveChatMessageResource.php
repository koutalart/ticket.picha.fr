<?php

declare(strict_types=1);

namespace HiEvents\Resources\LiveChat;

use HiEvents\DomainObjects\LiveChatMessageDomainObject;
use HiEvents\Resources\BaseResource;

/**
 * @mixin LiveChatMessageDomainObject
 */
class LiveChatMessageResource extends BaseResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->getId(),
            'author_type' => $this->getAuthorType(),
            'content' => $this->getContent(),
            'created_at' => $this->getCreatedAt(),
        ];
    }
}
