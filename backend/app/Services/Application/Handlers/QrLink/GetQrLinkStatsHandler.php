<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\QrLink;

use HiEvents\DomainObjects\Generated\QrLinkDomainObjectAbstract;
use HiEvents\DomainObjects\QrLinkDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Repository\Interfaces\QrLinkRepositoryInterface;
use HiEvents\Services\Domain\QrLink\DTO\QrLinkStatsDTO;
use HiEvents\Services\Domain\QrLink\QrLinkStatsService;

class GetQrLinkStatsHandler
{
    public function __construct(
        private readonly QrLinkRepositoryInterface $qrLinkRepository,
        private readonly OrganizerRepositoryInterface $organizerRepository,
        private readonly QrLinkStatsService $statsService,
    ) {}

    /**
     * @throws ResourceNotFoundException
     */
    public function handle(int $organizerId, int $qrLinkId, ?int $days): QrLinkStatsDTO
    {
        /** @var QrLinkDomainObject|null $qrLink */
        $qrLink = $this->qrLinkRepository->findFirstWhere([
            QrLinkDomainObjectAbstract::ID => $qrLinkId,
            QrLinkDomainObjectAbstract::ORGANIZER_ID => $organizerId,
        ]);

        if ($qrLink === null) {
            throw new ResourceNotFoundException(__('QR code not found'));
        }

        $timezone = $this->organizerRepository->findById($organizerId)->getTimezone() ?: 'UTC';

        return $this->statsService->getStats($qrLink, $days, $timezone);
    }
}
