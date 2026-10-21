<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\Generated\KioskPrintJobDomainObjectAbstract;
use HiEvents\DomainObjects\KioskPrintJobDomainObject;
use HiEvents\DomainObjects\Status\KioskPrintJobStatus;
use HiEvents\Models\KioskPrintJob;
use HiEvents\Repository\Interfaces\KioskPrintJobRepositoryInterface;
use Illuminate\Support\Collection;

/**
 * @extends BaseRepository<KioskPrintJobDomainObject>
 */
class KioskPrintJobRepository extends BaseRepository implements KioskPrintJobRepositoryInterface
{
    protected function getModel(): string
    {
        return KioskPrintJob::class;
    }

    public function getDomainObject(): string
    {
        return KioskPrintJobDomainObject::class;
    }

    public function findPendingForEvent(int $eventId, int $limit): Collection
    {
        $models = KioskPrintJob::query()
            ->where(KioskPrintJobDomainObjectAbstract::EVENT_ID, $eventId)
            ->where(KioskPrintJobDomainObjectAbstract::STATUS, KioskPrintJobStatus::PENDING->name)
            ->orderBy(KioskPrintJobDomainObjectAbstract::ID)
            ->limit($limit)
            ->get();

        return $this->handleResults($models);
    }
}
