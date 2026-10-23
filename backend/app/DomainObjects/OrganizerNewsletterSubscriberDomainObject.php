<?php

namespace HiEvents\DomainObjects;

use HiEvents\DomainObjects\Enums\NewsletterSubscriberSource;

class OrganizerNewsletterSubscriberDomainObject extends Generated\OrganizerNewsletterSubscriberDomainObjectAbstract
{
    private ?string $eventTitle = null;

    public function getEventTitle(): ?string
    {
        return $this->eventTitle;
    }

    public function setEventTitle(?string $eventTitle): self
    {
        $this->eventTitle = $eventTitle;

        return $this;
    }

    public function getSourceLabel(): string
    {
        if ($this->getSource() === NewsletterSubscriberSource::REGISTRATION->name) {
            return __('Registration: :event', ['event' => $this->getEventTitle() ?? __('Deleted event')]);
        }

        return __('Footer');
    }
}
