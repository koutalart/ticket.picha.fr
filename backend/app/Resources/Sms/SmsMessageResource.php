<?php

declare(strict_types=1);

namespace HiEvents\Resources\Sms;

use HiEvents\DomainObjects\SmsMessageDomainObject;
use HiEvents\Resources\BaseResource;

/**
 * @mixin SmsMessageDomainObject
 */
class SmsMessageResource extends BaseResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->getId(),
            'direction' => $this->getDirection(),
            'author_type' => $this->getAuthorType(),
            'content' => $this->getContent(),
            'status' => $this->getStatus(),
            'error_message' => $this->getErrorMessage(),
            'sent_by_user_id' => $this->getSentByUserId(),
            'auto_sent' => $this->getAutoReplyToMessageId() !== null,
            'auto_reply_type' => $this->getAutoReplyType(),
            'channel' => $this->getChannel(),
            'provider_timestamp' => $this->getProviderTimestamp(),
            'created_at' => $this->getCreatedAt(),
        ];
    }
}
