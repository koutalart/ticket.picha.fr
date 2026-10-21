<?php

declare(strict_types=1);

namespace HiEvents\Resources\Sms;

use HiEvents\DomainObjects\SmsCampaignDomainObject;
use HiEvents\Resources\BaseResource;

/**
 * @mixin SmsCampaignDomainObject
 */
class SmsCampaignResource extends BaseResource
{
    public function toArray($request): array
    {
        $stats = $this->getStats();

        return [
            'id' => $this->getId(),
            'name' => $this->getName(),
            'message' => $this->getMessage(),
            'event_id' => $this->getEventId(),
            'event_title' => $this->getEvent()?->getTitle(),
            'currency' => $this->getEvent()?->getCurrency(),
            'recipients_count' => $stats->recipients,
            'pending_count' => $stats->pending,
            'sent_count' => $stats->sent,
            'delivered_count' => $stats->delivered,
            'failed_count' => $stats->failed,
            'visits_count' => $stats->visits,
            'clickers_count' => $stats->clickers,
            'orders_count' => $stats->orders,
            'revenue_gross' => $stats->revenueGross,
            'created_at' => $this->getCreatedAt(),
        ];
    }
}
