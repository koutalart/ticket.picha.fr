<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\PrintJobDomainObject;
use HiEvents\Models\PrintJob;
use HiEvents\Repository\Interfaces\PrintJobRepositoryInterface;

/**
 * @extends BaseRepository<PrintJobDomainObject>
 */
class PrintJobRepository extends BaseRepository implements PrintJobRepositoryInterface
{
    protected function getModel(): string
    {
        return PrintJob::class;
    }

    public function getDomainObject(): string
    {
        return PrintJobDomainObject::class;
    }
}
