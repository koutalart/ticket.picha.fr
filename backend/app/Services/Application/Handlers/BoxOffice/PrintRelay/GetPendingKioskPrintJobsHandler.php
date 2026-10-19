<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\BoxOffice\PrintRelay;

use HiEvents\DomainObjects\KioskPrintJobDomainObject;
use HiEvents\Exceptions\InvalidKioskPrintRelayTokenException;
use HiEvents\Repository\Interfaces\KioskPrintJobRepositoryInterface;
use HiEvents\Services\Domain\BoxOffice\KioskPrintRelayService;
use Illuminate\Support\Collection;

class GetPendingKioskPrintJobsHandler
{
    public const MAX_JOBS = 20;

    public function __construct(
        private readonly KioskPrintRelayService $kioskPrintRelayService,
        private readonly KioskPrintJobRepositoryInterface $kioskPrintJobRepository,
    ) {}

    /**
     * @return Collection<KioskPrintJobDomainObject>
     *
     * @throws InvalidKioskPrintRelayTokenException
     */
    public function handle(?string $relayToken): Collection
    {
        $relay = $this->kioskPrintRelayService->authenticate($relayToken);

        $this->kioskPrintRelayService->markSeen($relay);

        return $this->kioskPrintJobRepository->findPendingForEvent($relay->getEventId(), self::MAX_JOBS);
    }
}
