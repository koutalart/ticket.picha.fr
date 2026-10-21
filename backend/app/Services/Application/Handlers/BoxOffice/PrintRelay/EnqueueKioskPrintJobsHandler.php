<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\BoxOffice\PrintRelay;

use HiEvents\DomainObjects\Generated\KioskPrintJobDomainObjectAbstract;
use HiEvents\DomainObjects\Status\KioskPrintJobStatus;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Repository\Interfaces\KioskPrintJobRepositoryInterface;
use HiEvents\Services\Application\Handlers\BoxOffice\PrintRelay\DTO\EnqueueKioskPrintJobsDTO;
use HiEvents\Services\Domain\BoxOffice\BoxOfficeTicketZplService;
use Illuminate\Database\DatabaseManager;

/**
 * Browser kiosks cannot reach the venue Zebra and the server cannot either:
 * the ZPL is queued here and pulled by the relay running on a venue computer.
 */
class EnqueueKioskPrintJobsHandler
{
    public function __construct(
        private readonly KioskPrintJobRepositoryInterface $kioskPrintJobRepository,
        private readonly BoxOfficeTicketZplService $boxOfficeTicketZplService,
        private readonly DatabaseManager $databaseManager,
    ) {}

    /**
     * @return int[] created job ids, in the requested order
     *
     * @throws ResourceNotFoundException
     */
    public function handle(EnqueueKioskPrintJobsDTO $dto): array
    {
        $tickets = [];
        foreach ($dto->attendee_public_ids as $attendeePublicId) {
            $tickets[$attendeePublicId] = $this->boxOfficeTicketZplService->build(
                $dto->event_id,
                $attendeePublicId,
                $dto->label_format,
            );
        }

        return $this->databaseManager->transaction(function () use ($dto, $tickets): array {
            $jobIds = [];
            foreach ($tickets as $attendeePublicId => $ticket) {
                $job = $this->kioskPrintJobRepository->create([
                    KioskPrintJobDomainObjectAbstract::EVENT_ID => $dto->event_id,
                    KioskPrintJobDomainObjectAbstract::ATTENDEE_PUBLIC_ID => (string) $attendeePublicId,
                    KioskPrintJobDomainObjectAbstract::ZPL => $ticket->zpl,
                    KioskPrintJobDomainObjectAbstract::STATUS => KioskPrintJobStatus::PENDING->name,
                    KioskPrintJobDomainObjectAbstract::ATTEMPTS => 0,
                    KioskPrintJobDomainObjectAbstract::CREATED_BY_USER_ID => $dto->user_id,
                ]);

                $this->boxOfficeTicketZplService->recordPrintJob($ticket->attendee_id, $dto->user_id);

                $jobIds[] = $job->getId();
            }

            return $jobIds;
        });
    }
}
