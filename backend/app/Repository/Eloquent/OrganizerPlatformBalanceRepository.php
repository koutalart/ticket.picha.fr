<?php

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\Generated\OrganizerPlatformBalanceDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerPlatformBalanceDomainObject;
use HiEvents\Models\OrganizerPlatformBalance;
use HiEvents\Repository\Interfaces\OrganizerPlatformBalanceRepositoryInterface;
use Illuminate\Support\Collection;

/**
 * @extends BaseRepository<OrganizerPlatformBalanceDomainObject>
 */
class OrganizerPlatformBalanceRepository extends BaseRepository implements OrganizerPlatformBalanceRepositoryInterface
{
    protected function getModel(): string
    {
        return OrganizerPlatformBalance::class;
    }

    public function getDomainObject(): string
    {
        return OrganizerPlatformBalanceDomainObject::class;
    }

    public function getRemainingTotal(int $organizerId, string $currency): float
    {
        return (float) OrganizerPlatformBalance::query()
            ->where(OrganizerPlatformBalanceDomainObjectAbstract::ORGANIZER_ID, $organizerId)
            ->where(OrganizerPlatformBalanceDomainObjectAbstract::CURRENCY, strtoupper($currency))
            ->where(OrganizerPlatformBalanceDomainObjectAbstract::REMAINING_AMOUNT, '>', 0)
            ->whereNotNull(OrganizerPlatformBalanceDomainObjectAbstract::ORGANIZER_VALIDATED_AT)
            ->sum(OrganizerPlatformBalanceDomainObjectAbstract::REMAINING_AMOUNT);
    }

    public function findOpenForUpdate(int $organizerId, string $currency): Collection
    {
        $models = OrganizerPlatformBalance::query()
            ->where(OrganizerPlatformBalanceDomainObjectAbstract::ORGANIZER_ID, $organizerId)
            ->where(OrganizerPlatformBalanceDomainObjectAbstract::CURRENCY, strtoupper($currency))
            ->where(OrganizerPlatformBalanceDomainObjectAbstract::REMAINING_AMOUNT, '>', 0)
            ->whereNotNull(OrganizerPlatformBalanceDomainObjectAbstract::ORGANIZER_VALIDATED_AT)
            ->orderBy(OrganizerPlatformBalanceDomainObjectAbstract::ID)
            ->lockForUpdate()
            ->get();

        return $this->handleResults($models);
    }
}
