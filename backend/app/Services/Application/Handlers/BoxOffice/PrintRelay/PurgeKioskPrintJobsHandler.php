<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\BoxOffice\PrintRelay;

use HiEvents\DomainObjects\Generated\KioskPrintJobDomainObjectAbstract;
use HiEvents\Repository\Interfaces\KioskPrintJobRepositoryInterface;

class PurgeKioskPrintJobsHandler
{
    public const RETENTION_HOURS = 48;

    public function __construct(
        private readonly KioskPrintJobRepositoryInterface $kioskPrintJobRepository,
    ) {}

    public function handle(): int
    {
        return $this->kioskPrintJobRepository->deleteWhere([
            [KioskPrintJobDomainObjectAbstract::CREATED_AT, '<', now()->subHours(self::RETENTION_HOURS)->toDateTimeString()],
        ]);
    }
}
