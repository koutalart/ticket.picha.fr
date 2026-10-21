<?php

declare(strict_types=1);

namespace HiEvents\Resources\Sms;

use HiEvents\DomainObjects\SmsConversationDomainObject;
use HiEvents\Resources\BaseResource;

/**
 * @mixin SmsConversationDomainObject
 */
class SmsConversationResource extends BaseResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->getId(),
            'phone' => $this->getPhone(),
            'contact_name' => $this->getContactName(),
            'status' => $this->getStatus(),
            'needs_human_reason' => $this->getNeedsHumanReason(),
            'unread_count' => $this->getUnreadCount(),
            'last_message_at' => $this->getLastMessageAt(),
            'last_message_preview' => $this->getLastMessagePreview(),
            'last_message_direction' => $this->getLastMessageDirection(),
            'draft_reply' => $this->getDraftReply(),
            'draft_source' => $this->getDraftSource(),
            'draft_generated_at' => $this->getDraftGeneratedAt(),
            'created_at' => $this->getCreatedAt(),
        ];
    }
}
