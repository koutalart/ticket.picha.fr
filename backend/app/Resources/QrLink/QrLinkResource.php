<?php

declare(strict_types=1);

namespace HiEvents\Resources\QrLink;

use HiEvents\DomainObjects\QrLinkDomainObject;
use HiEvents\Resources\BaseResource;
use HiEvents\Services\Domain\QrLink\QrLinkUrlService;

/**
 * @mixin QrLinkDomainObject
 */
class QrLinkResource extends BaseResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->getId(),
            'short_id' => $this->getShortId(),
            'public_url' => app(QrLinkUrlService::class)->getPublicUrl($this->resource),
            'name' => $this->getName(),
            'destination_type' => $this->getDestinationType(),
            'destination_url' => $this->getDestinationUrl(),
            'event_id' => $this->getEventId(),
            'event_title' => $this->getEvent()?->getTitle(),
            'title' => $this->getTitle(),
            'description' => $this->getDescription(),
            'social_links' => $this->getSocialLinks() ?? [],
            'design' => $this->getDesign() ?? (object) [],
            'status' => $this->getStatus(),
            'scans_count' => $this->getScansCount(),
            'unique_scans_count' => $this->getUniqueScansCount(),
            'clicks_count' => $this->getClicksCount(),
            'orders_count' => $this->getOrdersCount(),
            'revenue_gross' => $this->getRevenueGross(),
            'created_at' => $this->getCreatedAt(),
            'updated_at' => $this->getUpdatedAt(),
        ];
    }
}
