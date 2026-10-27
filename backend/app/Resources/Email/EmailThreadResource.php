<?php

declare(strict_types=1);

namespace HiEvents\Resources\Email;

use HiEvents\DomainObjects\EmailThreadDomainObject;
use HiEvents\Resources\BaseResource;

/**
 * @mixin EmailThreadDomainObject
 */
class EmailThreadResource extends BaseResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->getId(),
            'email' => $this->getEmail(),
            'contact_name' => $this->getContactName(),
            'status' => $this->getStatus(),
            'unread_count' => $this->getUnreadCount(),
            'last_message_at' => $this->getLastMessageAt(),
            'last_message_subject' => $this->getLastMessageSubject(),
            'last_message_preview' => $this->getLastMessagePreview(),
            'last_message_direction' => $this->getLastMessageDirection(),
            'last_message_status' => $this->getLastMessageStatus(),
            'created_at' => $this->getCreatedAt(),
        ];
    }
}
