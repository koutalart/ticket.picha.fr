<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\BoxOfficeSaleDomainObject;
use HiEvents\Models\BoxOfficeSale;
use HiEvents\Repository\Interfaces\BoxOfficeSaleRepositoryInterface;

/**
 * @extends BaseRepository<BoxOfficeSaleDomainObject>
 */
class BoxOfficeSaleRepository extends BaseRepository implements BoxOfficeSaleRepositoryInterface
{
    protected function getModel(): string
    {
        return BoxOfficeSale::class;
    }

    public function getDomainObject(): string
    {
        return BoxOfficeSaleDomainObject::class;
    }
}
