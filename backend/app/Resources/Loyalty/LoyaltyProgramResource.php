<?php

declare(strict_types=1);

namespace HiEvents\Resources\Loyalty;

use HiEvents\DomainObjects\LoyaltyProgramDomainObject;
use HiEvents\Resources\BaseResource;

/**
 * @mixin LoyaltyProgramDomainObject
 */
class LoyaltyProgramResource extends BaseResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->getId(),
            'organizer_id' => $this->getOrganizerId(),
            'name' => $this->getName(),
            'reward_description' => $this->getRewardDescription(),
            'stamps_required' => $this->getStampsRequired(),
            'background_color' => $this->getBackgroundColor(),
            'foreground_color' => $this->getForegroundColor(),
            'label_color' => $this->getLabelColor(),
            'logo_url' => $this->getLogoUrl(),
            'locations' => is_array($this->getLocations()) ? $this->getLocations() : [],
            'max_distance' => $this->getMaxDistance(),
            'beacon_uuid' => $this->getBeaconUuid(),
            'beacon_major' => $this->getBeaconMajor(),
            'beacon_minor' => $this->getBeaconMinor(),
            'beacon_relevant_text' => $this->getBeaconRelevantText(),
            'is_active' => $this->getIsActive(),
        ];
    }
}
