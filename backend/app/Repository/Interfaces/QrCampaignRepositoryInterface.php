<?php

declare(strict_types=1);

namespace HiEvents\Repository\Interfaces;

use HiEvents\DomainObjects\QrCampaignDomainObject;

/**
 * @extends RepositoryInterface<QrCampaignDomainObject>
 */
interface QrCampaignRepositoryInterface extends RepositoryInterface
{
    public function nameExists(int $organizerId, string $name, ?int $exceptId = null): bool;
}
