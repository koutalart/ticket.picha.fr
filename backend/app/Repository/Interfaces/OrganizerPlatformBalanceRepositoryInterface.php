<?php

namespace HiEvents\Repository\Interfaces;

use HiEvents\DomainObjects\OrganizerPlatformBalanceDomainObject;
use Illuminate\Support\Collection;

/**
 * @extends RepositoryInterface<OrganizerPlatformBalanceDomainObject>
 */
interface OrganizerPlatformBalanceRepositoryInterface extends RepositoryInterface
{
    public function getRemainingTotal(int $organizerId, string $currency): float;

    /**
     * Balances still owed, oldest first, locked until the end of the transaction.
     *
     * @return Collection<int, OrganizerPlatformBalanceDomainObject>
     */
    public function findOpenForUpdate(int $organizerId, string $currency): Collection;
}
