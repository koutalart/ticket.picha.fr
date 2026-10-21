<?php

declare(strict_types=1);

namespace HiEvents\Repository\Interfaces;

use HiEvents\DomainObjects\KioskPrintJobDomainObject;
use Illuminate\Support\Collection;

/**
 * @extends RepositoryInterface<KioskPrintJobDomainObject>
 */
interface KioskPrintJobRepositoryInterface extends RepositoryInterface
{
    /**
     * @return Collection<KioskPrintJobDomainObject>
     */
    public function findPendingForEvent(int $eventId, int $limit): Collection;
}
