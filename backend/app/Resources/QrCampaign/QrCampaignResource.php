<?php

declare(strict_types=1);

namespace HiEvents\Resources\QrCampaign;

use HiEvents\DomainObjects\QrCampaignDomainObject;
use HiEvents\Resources\BaseResource;

/**
 * @mixin QrCampaignDomainObject
 */
class QrCampaignResource extends BaseResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->getId(),
            'name' => $this->getName(),
            'created_at' => $this->getCreatedAt(),
        ];
    }
}
