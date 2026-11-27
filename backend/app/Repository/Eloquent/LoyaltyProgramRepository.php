<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\LoyaltyProgramDomainObject;
use HiEvents\Models\LoyaltyProgram;
use HiEvents\Repository\Interfaces\LoyaltyProgramRepositoryInterface;

/**
 * @extends BaseRepository<LoyaltyProgramDomainObject>
 */
class LoyaltyProgramRepository extends BaseRepository implements LoyaltyProgramRepositoryInterface
{
    protected function getModel(): string
    {
        return LoyaltyProgram::class;
    }

    public function getDomainObject(): string
    {
        return LoyaltyProgramDomainObject::class;
    }
}
