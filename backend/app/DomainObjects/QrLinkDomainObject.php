<?php

namespace HiEvents\DomainObjects;

use HiEvents\DomainObjects\Enums\QrLinkDestinationType;

class QrLinkDomainObject extends Generated\QrLinkDomainObjectAbstract
{
    public const SOCIAL_PLATFORMS = [
        'instagram',
        'facebook',
        'tiktok',
        'whatsapp',
        'snapchat',
        'youtube',
        'x',
        'linkedin',
        'telegram',
        'threads',
        'website',
    ];

    private ?EventDomainObject $event = null;

    private int $ordersCount = 0;

    private float $revenueGross = 0.0;

    public function getEvent(): ?EventDomainObject
    {
        return $this->event;
    }

    public function setEvent(?EventDomainObject $event): self
    {
        $this->event = $event;

        return $this;
    }

    public function getOrdersCount(): int
    {
        return $this->ordersCount;
    }

    public function getRevenueGross(): float
    {
        return $this->revenueGross;
    }

    public function setOrderTotals(int $ordersCount, float $revenueGross): self
    {
        $this->ordersCount = $ordersCount;
        $this->revenueGross = $revenueGross;

        return $this;
    }

    public function getDestinationTypeEnum(): QrLinkDestinationType
    {
        return QrLinkDestinationType::from($this->getDestinationType());
    }
}
