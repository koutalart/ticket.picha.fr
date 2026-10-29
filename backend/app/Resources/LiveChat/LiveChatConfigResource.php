<?php

declare(strict_types=1);

namespace HiEvents\Resources\LiveChat;

use HiEvents\Resources\BaseResource;
use HiEvents\Services\Domain\LiveChat\DTO\LiveChatConfigDTO;

/**
 * @mixin LiveChatConfigDTO
 */
class LiveChatConfigResource extends BaseResource
{
    public function toArray($request): array
    {
        return [
            'enabled' => $this->enabled,
            'organizer_name' => $this->organizerName,
            'relay_line' => $this->relayLine,
            'max_message_length' => $this->maxMessageLength,
            'style' => $this->style->value,
            'avatar_url' => $this->avatarUrl,
            'greeting' => $this->greeting,
        ];
    }
}
