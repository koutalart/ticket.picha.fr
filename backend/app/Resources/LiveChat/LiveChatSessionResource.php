<?php

declare(strict_types=1);

namespace HiEvents\Resources\LiveChat;

use HiEvents\DomainObjects\LiveChatSessionDomainObject;
use HiEvents\Resources\BaseResource;

/**
 * Organizer view of a web chat (never public: it holds the contact details of the visitor).
 *
 * @mixin LiveChatSessionDomainObject
 */
class LiveChatSessionResource extends BaseResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->getId(),
            'event_id' => $this->getEventId(),
            'status' => $this->getStatus(),
            'visitor_phone' => $this->getVisitorPhone(),
            'visitor_email' => $this->getVisitorEmail(),
            'needs_follow_up' => $this->getNeedsFollowUp(),
            'follow_up_reason' => $this->getFollowUpReason(),
            'visitor_message_count' => $this->getVisitorMessageCount(),
            'sms_conversation_id' => $this->getSmsConversationId(),
            'contact_given_at' => $this->getContactGivenAt(),
            'last_message_at' => $this->getLastMessageAt(),
            'created_at' => $this->getCreatedAt(),
            'messages' => $this->getLiveChatMessages() !== null
                ? LiveChatMessageResource::collection($this->getLiveChatMessages())->toArray($request)
                : null,
        ];
    }
}
