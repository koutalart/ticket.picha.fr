<?php

namespace HiEvents\Repository\Interfaces;

use HiEvents\DomainObjects\OrganizerPlatformBalanceDomainObject;
use Illuminate\Support\Collection;

/**
 * @extends RepositoryInterface<OrganizerPlatformBalanceDomainObject>
 */
interface OrganizerPlatformBalanceRepositoryInterface extends RepositoryInterface
{
    /**
     * Total still owed and validated by the organizer: only this part is withheld.
     */
    public function getRemainingTotal(int $organizerId, string $currency): float;

    /**
     * Balances still owed and validated by the organizer, oldest first, locked until the end of the transaction.
     *
     * @return Collection<int, OrganizerPlatformBalanceDomainObject>
     */
    public function findOpenForUpdate(int $organizerId, string $currency): Collection;
}
