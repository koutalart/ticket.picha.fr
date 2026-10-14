<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\QrCampaign;

use HiEvents\DomainObjects\Generated\QrCampaignDomainObjectAbstract;
use HiEvents\DomainObjects\QrCampaignDomainObject;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Repository\Interfaces\QrCampaignRepositoryInterface;

class UpsertQrCampaignHandler
{
    public function __construct(
        private readonly QrCampaignRepositoryInterface $qrCampaignRepository,
    ) {}

    /**
     * @throws ResourceConflictException
     */
    public function create(int $accountId, int $organizerId, string $name): QrCampaignDomainObject
    {
        $name = $this->cleanName($name);
        $this->assertNameIsFree($organizerId, $name);

        return $this->qrCampaignRepository->create([
            QrCampaignDomainObjectAbstract::ACCOUNT_ID => $accountId,
            QrCampaignDomainObjectAbstract::ORGANIZER_ID => $organizerId,
            QrCampaignDomainObjectAbstract::NAME => $name,
        ]);
    }

    /**
     * @throws ResourceConflictException
     */
    public function rename(int $organizerId, int $campaignId, string $name): QrCampaignDomainObject
    {
        $name = $this->cleanName($name);
        $this->assertNameIsFree($organizerId, $name, $campaignId);

        return $this->qrCampaignRepository->updateByIdWhere(
            id: $campaignId,
            attributes: [QrCampaignDomainObjectAbstract::NAME => $name],
            where: [QrCampaignDomainObjectAbstract::ORGANIZER_ID => $organizerId],
        );
    }

    /**
     * @throws ResourceConflictException
     */
    private function assertNameIsFree(int $organizerId, string $name, ?int $exceptId = null): void
    {
        if ($this->qrCampaignRepository->nameExists($organizerId, $name, $exceptId)) {
            throw new ResourceConflictException(__('A campaign with this name already exists'));
        }
    }

    private function cleanName(string $name): string
    {
        return trim(strip_tags($name));
    }
}
