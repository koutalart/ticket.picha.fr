<?php

declare(strict_types=1);

namespace HiEvents\Resources\Loyalty;

use HiEvents\DomainObjects\LoyaltyProgramDomainObject;
use HiEvents\Resources\BaseResource;

/**
 * @mixin LoyaltyProgramDomainObject
 */
class LoyaltyProgramResourcePublic extends BaseResource
{
    public function toArray($request): array
    {
        return [
            'organizer_id' => $this->getOrganizerId(),
            'name' => $this->getName(),
            'reward_description' => $this->getRewardDescription(),
            'stamps_required' => $this->getStampsRequired(),
            'background_color' => $this->getBackgroundColor(),
            'foreground_color' => $this->getForegroundColor(),
            'label_color' => $this->getLabelColor(),
            'logo_url' => $this->getLogoUrl(),
        ];
    }
}
