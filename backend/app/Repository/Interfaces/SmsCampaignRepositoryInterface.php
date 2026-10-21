<?php

declare(strict_types=1);

namespace HiEvents\Repository\Interfaces;

use HiEvents\DomainObjects\SmsCampaignDomainObject;
use HiEvents\Services\Domain\Sms\DTO\SmsCampaignStatsDTO;

/**
 * @extends RepositoryInterface<SmsCampaignDomainObject>
 */
interface SmsCampaignRepositoryInterface extends RepositoryInterface
{
    /**
     * @return array<int, SmsCampaignStatsDTO>
     */
    public function getStatsByCampaignIds(array $campaignIds): array;
}
