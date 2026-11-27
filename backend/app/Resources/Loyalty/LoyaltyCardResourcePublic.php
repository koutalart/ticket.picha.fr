<?php

declare(strict_types=1);

namespace HiEvents\Resources\Loyalty;

use HiEvents\DomainObjects\LoyaltyCardDomainObject;
use HiEvents\Resources\BaseResource;

/**
 * @mixin LoyaltyCardDomainObject
 */
class LoyaltyCardResourcePublic extends BaseResource
{
    public function toArray($request): array
    {
        return [
            'public_id' => $this->getPublicId(),
            'first_name' => $this->getFirstName(),
            'stamps' => $this->getStamps(),
            'rewards_earned' => $this->getRewardsEarned(),
        ];
    }
}
