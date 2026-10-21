<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\SmsOptOutDomainObject;
use HiEvents\Models\SmsOptOut;
use HiEvents\Repository\Interfaces\SmsOptOutRepositoryInterface;

/**
 * @extends BaseRepository<SmsOptOutDomainObject>
 */
class SmsOptOutRepository extends BaseRepository implements SmsOptOutRepositoryInterface
{
    protected function getModel(): string
    {
        return SmsOptOut::class;
    }

    public function getDomainObject(): string
    {
        return SmsOptOutDomainObject::class;
    }
}
