<?php

declare(strict_types=1);

namespace HiEvents\Resources\Email;

use HiEvents\DomainObjects\EmailMessageDomainObject;
use HiEvents\Resources\BaseResource;

/**
 * @mixin EmailMessageDomainObject
 */
class EmailMessageResource extends BaseResource
{
    public function toArray($request): array
    {
        $attachments = $this->getAttachments();

        return [
            'id' => $this->getId(),
            'email_thread_id' => $this->getEmailThreadId(),
            'direction' => $this->getDirection(),
            'source' => $this->getSource(),
            'event_id' => $this->getEventId(),
            'order_id' => $this->getOrderId(),
            'attendee_id' => $this->getAttendeeId(),
            'sent_by_user_id' => $this->getSentByUserId(),
            'from_email' => $this->getFromEmail(),
            'from_name' => $this->getFromName(),
            'to_email' => $this->getToEmail(),
            'subject' => $this->getSubject(),
            'preview' => $this->getPreview(),
            'body_text' => $this->getBodyText(),
            'body_html' => $this->getBodyHtml(),
            'attachments' => is_string($attachments) ? json_decode($attachments, true) : $attachments,
            'status' => $this->getStatus(),
            'error_message' => $this->getErrorMessage(),
            'delivered_at' => $this->getDeliveredAt(),
            'opened_at' => $this->getOpenedAt(),
            'status_updated_at' => $this->getStatusUpdatedAt(),
            'created_at' => $this->getCreatedAt(),
        ];
    }
}
