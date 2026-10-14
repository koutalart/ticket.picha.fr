<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\QrCampaignDomainObject;
use HiEvents\Models\QrCampaign;
use HiEvents\Repository\Interfaces\QrCampaignRepositoryInterface;

/**
 * @extends BaseRepository<QrCampaignDomainObject>
 */
class QrCampaignRepository extends BaseRepository implements QrCampaignRepositoryInterface
{
    protected function getModel(): string
    {
        return QrCampaign::class;
    }

    public function getDomainObject(): string
    {
        return QrCampaignDomainObject::class;
    }

    public function nameExists(int $organizerId, string $name, ?int $exceptId = null): bool
    {
        return QrCampaign::query()
            ->where('organizer_id', $organizerId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->when($exceptId, fn ($query) => $query->whereKeyNot($exceptId))
            ->exists();
    }
}
