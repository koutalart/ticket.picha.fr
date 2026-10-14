<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\QrCampaign;

use HiEvents\DomainObjects\Generated\QrCampaignDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\QrLinkDomainObjectAbstract;
use HiEvents\Repository\Interfaces\QrCampaignRepositoryInterface;
use HiEvents\Repository\Interfaces\QrLinkRepositoryInterface;
use Illuminate\Database\DatabaseManager;

class DeleteQrCampaignHandler
{
    public function __construct(
        private readonly QrCampaignRepositoryInterface $qrCampaignRepository,
        private readonly QrLinkRepositoryInterface $qrLinkRepository,
        private readonly DatabaseManager $databaseManager,
    ) {}

    public function handle(int $organizerId, int $campaignId): bool
    {
        return $this->databaseManager->transaction(function () use ($organizerId, $campaignId) {
            $this->qrLinkRepository->updateWhere(
                attributes: [QrLinkDomainObjectAbstract::QR_CAMPAIGN_ID => null],
                where: [
                    QrLinkDomainObjectAbstract::QR_CAMPAIGN_ID => $campaignId,
                    QrLinkDomainObjectAbstract::ORGANIZER_ID => $organizerId,
                ],
            );

            return $this->qrCampaignRepository->deleteWhere([
                QrCampaignDomainObjectAbstract::ID => $campaignId,
                QrCampaignDomainObjectAbstract::ORGANIZER_ID => $organizerId,
            ]) > 0;
        });
    }
}
