<?php

namespace HiEvents\Services\Application\Handlers\Organizer\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class SubscribeToOrganizerNewsletterDTO extends BaseDataObject
{
    public function __construct(
        public int     $event_id,
        public string  $email,
        public ?string $consent_text,
        public ?string $ip,
        public ?string $user_agent,
    )
    {
    }
}
