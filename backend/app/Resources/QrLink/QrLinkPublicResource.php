<?php

declare(strict_types=1);

namespace HiEvents\Resources\QrLink;

use HiEvents\DomainObjects\QrLinkDomainObject;
use HiEvents\Resources\BaseResource;
use HiEvents\Services\Domain\QrLink\QrLinkUrlService;

/**
 * @mixin QrLinkDomainObject
 */
class QrLinkPublicResource extends BaseResource
{
    public function toArray($request): array
    {
        $event = $this->getEvent();

        return [
            'short_id' => $this->getShortId(),
            'name' => $this->getName(),
            'organizer_id' => $this->getOrganizerId(),
            'event_id' => $this->getEventId(),
            'event_title' => $event?->getTitle(),
            'ticket_url' => $event ? app(QrLinkUrlService::class)->getEventUrl($event, $this->resource) : null,
            'destination_type' => $this->getDestinationType(),
            'title' => $this->getTitle(),
            'description' => $this->getDescription(),
            'social_links' => $this->getSocialLinks() ?? [],
            'design' => $this->getDesign() ?? (object) [],
        ];
    }
}
