<?php

namespace HiEvents\DomainObjects;

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
}
