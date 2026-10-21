<?php

namespace HiEvents\DomainObjects;

class SmsCampaignRecipientDomainObject extends Generated\SmsCampaignRecipientDomainObjectAbstract
{
    private ?QrLinkDomainObject $qrLink = null;

    public function getQrLink(): ?QrLinkDomainObject
    {
        return $this->qrLink;
    }

    public function setQrLink(?QrLinkDomainObject $qrLink): self
    {
        $this->qrLink = $qrLink;

        return $this;
    }
}
