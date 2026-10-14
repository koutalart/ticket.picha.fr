<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\QrCampaigns;

use HiEvents\DomainObjects\Generated\QrCampaignDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Repository\Eloquent\Value\OrderAndDirection;
use HiEvents\Repository\Interfaces\QrCampaignRepositoryInterface;
use HiEvents\Resources\QrCampaign\QrCampaignResource;
use Illuminate\Http\JsonResponse;

class GetQrCampaignsAction extends BaseAction
{
    public function __construct(
        private readonly QrCampaignRepositoryInterface $qrCampaignRepository,
    ) {}

    public function __invoke(int $organizerId): JsonResponse
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        $campaigns = $this->qrCampaignRepository->findWhere(
            where: [QrCampaignDomainObjectAbstract::ORGANIZER_ID => $organizerId],
            orderAndDirections: [new OrderAndDirection(QrCampaignDomainObjectAbstract::NAME)],
        );

        return $this->resourceResponse(QrCampaignResource::class, $campaigns);
    }
}
