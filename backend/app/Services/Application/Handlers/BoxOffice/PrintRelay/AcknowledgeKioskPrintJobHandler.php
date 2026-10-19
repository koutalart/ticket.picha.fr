<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\BoxOffice\PrintRelay;

use HiEvents\DomainObjects\Generated\KioskPrintJobDomainObjectAbstract;
use HiEvents\DomainObjects\KioskPrintJobDomainObject;
use HiEvents\DomainObjects\Status\KioskPrintJobStatus;
use HiEvents\Exceptions\InvalidKioskPrintRelayTokenException;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Repository\Interfaces\KioskPrintJobRepositoryInterface;
use HiEvents\Services\Application\Handlers\BoxOffice\PrintRelay\DTO\AcknowledgeKioskPrintJobDTO;
use HiEvents\Services\Domain\BoxOffice\KioskPrintRelayService;
use Illuminate\Support\Str;

/**
 * A failed send goes back to PENDING (the relay retries it on its next poll)
 * until MAX_ATTEMPTS failures, then stays FAILED.
 */
class AcknowledgeKioskPrintJobHandler
{
    public const MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly KioskPrintRelayService $kioskPrintRelayService,
        private readonly KioskPrintJobRepositoryInterface $kioskPrintJobRepository,
    ) {}

    /**
     * @throws InvalidKioskPrintRelayTokenException
     * @throws ResourceNotFoundException
     */
    public function handle(AcknowledgeKioskPrintJobDTO $dto): KioskPrintJobDomainObject
    {
        $relay = $this->kioskPrintRelayService->authenticate($dto->relay_token);

        /** @var KioskPrintJobDomainObject|null $job */
        $job = $this->kioskPrintJobRepository->findFirstWhere([
            KioskPrintJobDomainObjectAbstract::ID => $dto->job_id,
            KioskPrintJobDomainObjectAbstract::EVENT_ID => $relay->getEventId(),
        ]);

        if ($job === null) {
            throw new ResourceNotFoundException(__('Print job not found.'));
        }

        if ($job->getStatus() !== KioskPrintJobStatus::PENDING->name) {
            return $job;
        }

        if ($dto->status === KioskPrintJobStatus::SENT) {
            return $this->kioskPrintJobRepository->updateFromArray($job->getId(), [
                KioskPrintJobDomainObjectAbstract::STATUS => KioskPrintJobStatus::SENT->name,
                KioskPrintJobDomainObjectAbstract::SENT_AT => now()->toDateTimeString(),
                KioskPrintJobDomainObjectAbstract::LAST_ERROR => null,
            ]);
        }

        $attempts = $job->getAttempts() + 1;

        return $this->kioskPrintJobRepository->updateFromArray($job->getId(), [
            KioskPrintJobDomainObjectAbstract::STATUS => $attempts < self::MAX_ATTEMPTS
                ? KioskPrintJobStatus::PENDING->name
                : KioskPrintJobStatus::FAILED->name,
            KioskPrintJobDomainObjectAbstract::ATTEMPTS => $attempts,
            KioskPrintJobDomainObjectAbstract::LAST_ERROR => $dto->error !== null ? Str::limit($dto->error, 250) : null,
        ]);
    }
}
