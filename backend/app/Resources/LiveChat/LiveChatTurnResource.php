<?php

declare(strict_types=1);

namespace HiEvents\Resources\LiveChat;

use HiEvents\Resources\BaseResource;
use HiEvents\Services\Domain\LiveChat\DTO\LiveChatTurnDTO;

/**
 * Public view of a web chat: the opaque session id is only ever sent in the body (never in a URL).
 *
 * @mixin LiveChatTurnDTO
 */
class LiveChatTurnResource extends BaseResource
{
    public function toArray($request): array
    {
        return [
            'session_id' => $this->session->getPublicId(),
            'greeting' => $this->greeting,
            'messages' => LiveChatMessageResource::collection($this->messages)->toArray($request),
            'ask_contact' => $this->askContact,
            'contact_form_fallback' => $this->contactFormFallback,
            'contact_given' => $this->session->getVisitorPhone() !== null || $this->session->getVisitorEmail() !== null,
        ];
    }
}
