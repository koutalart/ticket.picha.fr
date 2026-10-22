<?php

namespace HiEvents\Resources\Contact;

use HiEvents\DomainObjects\ContactDomainObject;
use HiEvents\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * @mixin ContactDomainObject
 */
class ContactResource extends BaseResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getId(),
            'organizer_id' => $this->getOrganizerId(),
            'first_name' => $this->getFirstName(),
            'last_name' => $this->getLastName(),
            'email' => $this->getEmail(),
            'phone' => $this->getPhone(),
            'public_code' => $this->getPublicCode(),
            'source' => $this->getSource(),
            'email_consent' => $this->hasEmailConsent(),
            'email_consent_at' => $this->getEmailConsentAt(),
            'email_consent_source' => $this->getEmailConsentSource(),
            'email_unsubscribed_at' => $this->getEmailUnsubscribedAt(),
            'sms_consent' => $this->hasSmsConsent(),
            'sms_consent_at' => $this->getSmsConsentAt(),
            'sms_consent_source' => $this->getSmsConsentSource(),
            'sms_unsubscribed_at' => $this->getSmsUnsubscribedAt(),
            'orders_count' => $this->getOrdersCount(),
            'events_count' => $this->getEventsCount(),
            'attended_events_count' => $this->getAttendedEventsCount(),
            'total_spent' => (float) $this->getTotalSpent(),
            'first_order_at' => $this->getFirstOrderAt(),
            'last_order_at' => $this->getLastOrderAt(),
            'tags' => ContactTagResource::collection($this->getContactTags() ?? collect()),
            'created_at' => $this->getCreatedAt(),
        ];
    }
}
