<?php

namespace HiEvents\DomainObjects;

class EventBoxOfficeOperatorDomainObject extends Generated\EventBoxOfficeOperatorDomainObjectAbstract
{
    public ?UserDomainObject $user = null;

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
