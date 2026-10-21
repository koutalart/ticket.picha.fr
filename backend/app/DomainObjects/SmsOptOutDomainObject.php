<?php

namespace HiEvents\DomainObjects;

class SmsOptOutDomainObject extends Generated\SmsOptOutDomainObjectAbstract
{
    private ?string $contactName = null;

    public function getContactName(): ?string
    {
        return $this->contactName;
    }

    public function setContactName(?string $contactName): self
    {
        $this->contactName = $contactName;

        return $this;
    }
}
