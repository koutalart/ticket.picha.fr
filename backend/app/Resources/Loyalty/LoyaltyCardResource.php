<?php

declare(strict_types=1);

namespace HiEvents\Resources\Loyalty;

use HiEvents\DomainObjects\LoyaltyCardDomainObject;
use HiEvents\Resources\BaseResource;

/**
 * @mixin LoyaltyCardDomainObject
 */
class LoyaltyCardResource extends BaseResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->getId(),
            'first_name' => $this->getFirstName(),
            'email' => $this->getEmail(),
            'phone' => $this->getPhone(),
            'stamps' => $this->getStamps(),
            'rewards_earned' => $this->getRewardsEarned(),
            'total_stamps' => $this->getTotalStamps(),
            'last_stamped_at' => $this->getLastStampedAt(),
        ];
    }
}
