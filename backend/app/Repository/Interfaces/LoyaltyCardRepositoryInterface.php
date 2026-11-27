<?php

declare(strict_types=1);

namespace HiEvents\Repository\Interfaces;

use HiEvents\DomainObjects\LoyaltyCardDomainObject;

/**
 * @extends RepositoryInterface<LoyaltyCardDomainObject>
 */
interface LoyaltyCardRepositoryInterface extends RepositoryInterface
{
    public function lockForUpdateById(int $id): ?LoyaltyCardDomainObject;
}
