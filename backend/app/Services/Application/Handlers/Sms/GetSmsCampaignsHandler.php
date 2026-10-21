<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Sms;

use HiEvents\DomainObjects\Enums\SmsCampaignType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Generated\SmsCampaignDomainObjectAbstract;
use HiEvents\DomainObjects\SmsCampaignDomainObject;
use HiEvents\Repository\Eloquent\Value\OrderAndDirection;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\SmsCampaignRepositoryInterface;
use HiEvents\Services\Domain\Sms\DTO\SmsCampaignStatsDTO;
use Illuminate\Support\Collection;

class GetSmsCampaignsHandler
{
    public function __construct(
        private readonly SmsCampaignRepositoryInterface $campaignRepository,
    ) {}

    /**
     * @return Collection<SmsCampaignDomainObject>
     */
    public function handle(int $organizerId): Collection
    {
        $campaigns = $this->campaignRepository
            ->loadRelation(new Relationship(EventDomainObject::class, name: 'event'))
            ->findWhere(
                where: [
                    SmsCampaignDomainObjectAbstract::ORGANIZER_ID => $organizerId,
                    [SmsCampaignDomainObjectAbstract::TYPE, '!=', SmsCampaignType::REMINDER->value],
                ],
                orderAndDirections: [new OrderAndDirection(SmsCampaignDomainObjectAbstract::CREATED_AT, 'desc')],
            );

        $stats = $this->campaignRepository->getStatsByCampaignIds(
            $campaigns->map(fn (SmsCampaignDomainObject $campaign) => $campaign->getId())->all()
        );

        return $campaigns->each(fn (SmsCampaignDomainObject $campaign) => $campaign->setStats(
            $stats[$campaign->getId()] ?? new SmsCampaignStatsDTO
        ));
    }
}
