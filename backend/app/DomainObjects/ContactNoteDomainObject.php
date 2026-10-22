<?php

namespace HiEvents\DomainObjects;

class ContactNoteDomainObject extends Generated\ContactNoteDomainObjectAbstract
{
    private ?UserDomainObject $user = null;

    public function setUser(?UserDomainObject $user): self
    {
        $this->user = $user;

        return $this;
    }

    public function getUser(): ?UserDomainObject
    {
        return $this->user;
    }
}
