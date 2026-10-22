<?php

declare(strict_types=1);

namespace HiEvents\Resources\Organizer;

use HiEvents\DomainObjects\OrganizerNewsletterSubscriberDomainObject;
use HiEvents\Resources\BaseResource;

/**
 * @mixin OrganizerNewsletterSubscriberDomainObject
 */
class OrganizerNewsletterSubscriberResource extends BaseResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->getId(),
            'email' => $this->getEmail(),
            'event_id' => $this->getEventId(),
            'event_title' => $this->getEventTitle(),
            'consent_text' => $this->getConsentText(),
            'consented_at' => $this->getConsentedAt(),
        ];
    }
}
