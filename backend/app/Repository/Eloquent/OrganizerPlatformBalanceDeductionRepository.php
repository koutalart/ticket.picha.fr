<?php

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\OrganizerPlatformBalanceDeductionDomainObject;
use HiEvents\Models\OrganizerPlatformBalanceDeduction;
use HiEvents\Repository\Interfaces\OrganizerPlatformBalanceDeductionRepositoryInterface;

/**
 * @extends BaseRepository<OrganizerPlatformBalanceDeductionDomainObject>
 */
class OrganizerPlatformBalanceDeductionRepository extends BaseRepository implements OrganizerPlatformBalanceDeductionRepositoryInterface
{
    protected function getModel(): string
    {
        return OrganizerPlatformBalanceDeduction::class;
    }

    public function getDomainObject(): string
    {
        return OrganizerPlatformBalanceDeductionDomainObject::class;
    }
}
