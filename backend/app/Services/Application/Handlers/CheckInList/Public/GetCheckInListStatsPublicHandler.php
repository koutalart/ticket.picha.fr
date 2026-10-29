<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\CheckInList\Public;

use HiEvents\DomainObjects\CheckInListDomainObject;
use HiEvents\DomainObjects\Generated\CheckInListDomainObjectAbstract;
use HiEvents\Repository\DTO\CheckInListLiveStatsDTO;
use HiEvents\Repository\Interfaces\CheckInListRepositoryInterface;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

class GetCheckInListStatsPublicHandler
{
    public function __construct(
        private readonly CheckInListRepositoryInterface $checkInListRepository,
    ) {}

    public function handle(string $shortId): CheckInListLiveStatsDTO
    {
        /** @var CheckInListDomainObject|null $checkInList */
        $checkInList = $this->checkInListRepository->findFirstWhere([
            CheckInListDomainObjectAbstract::SHORT_ID => $shortId,
        ]);

        if ($checkInList === null) {
            throw new ResourceNotFoundException(__('Check-in list not found'));
        }

        return $this->checkInListRepository->getLiveStatsById($checkInList->getId());
    }
}
