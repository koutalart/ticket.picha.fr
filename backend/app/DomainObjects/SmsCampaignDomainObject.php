<?php

namespace HiEvents\DomainObjects;

use HiEvents\Services\Domain\Sms\DTO\SmsCampaignStatsDTO;

class SmsCampaignDomainObject extends Generated\SmsCampaignDomainObjectAbstract
{
    private ?EventDomainObject $event = null;

    private ?SmsCampaignStatsDTO $stats = null;

    public function getEvent(): ?EventDomainObject
    {
        return $this->event;
    }

    public function setEvent(?EventDomainObject $event): self
    {
        $this->event = $event;

        return $this;
    }

    public function getStats(): SmsCampaignStatsDTO
    {
        return $this->stats ?? new SmsCampaignStatsDTO;
    }

    public function setStats(SmsCampaignStatsDTO $stats): self
    {
        $this->stats = $stats;

        return $this;
    }
}
