<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\BoxOffice\PrintRelay;

use Carbon\Carbon;
use HiEvents\DomainObjects\Generated\KioskPrintJobDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\KioskPrintRelayDomainObjectAbstract;
use HiEvents\DomainObjects\KioskPrintRelayDomainObject;
use HiEvents\DomainObjects\Status\KioskPrintJobStatus;
use HiEvents\Repository\Interfaces\KioskPrintJobRepositoryInterface;
use HiEvents\Repository\Interfaces\KioskPrintRelayRepositoryInterface;
use HiEvents\Services\Application\Handlers\BoxOffice\PrintRelay\DTO\KioskPrintRelayStatusDTO;

class GetKioskPrintRelayStatusHandler
{
    public function __construct(
        private readonly KioskPrintRelayRepositoryInterface $kioskPrintRelayRepository,
        private readonly KioskPrintJobRepositoryInterface $kioskPrintJobRepository,
    ) {}

    public function handle(int $eventId): KioskPrintRelayStatusDTO
    {
        /** @var KioskPrintRelayDomainObject|null $relay */
        $relay = $this->kioskPrintRelayRepository->findFirstWhere([
            KioskPrintRelayDomainObjectAbstract::EVENT_ID => $eventId,
        ]);

        $lastSeenAt = $relay?->getLastSeenAt();

        return new KioskPrintRelayStatusDTO(
            configured: $relay !== null,
            last_seen_at: $lastSeenAt ? Carbon::parse($lastSeenAt, 'UTC')->toIso8601ZuluString() : null,
            pending_jobs: $this->kioskPrintJobRepository->countWhere([
                KioskPrintJobDomainObjectAbstract::EVENT_ID => $eventId,
                KioskPrintJobDomainObjectAbstract::STATUS => KioskPrintJobStatus::PENDING->name,
            ]),
        );
    }
}
