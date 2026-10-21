<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\SmsCampaignRecipientDomainObject;
use HiEvents\Models\SmsCampaignRecipient;
use HiEvents\Repository\Interfaces\SmsCampaignRecipientRepositoryInterface;

/**
 * @extends BaseRepository<SmsCampaignRecipientDomainObject>
 */
class SmsCampaignRecipientRepository extends BaseRepository implements SmsCampaignRecipientRepositoryInterface
{
    protected function getModel(): string
    {
        return SmsCampaignRecipient::class;
    }

    public function getDomainObject(): string
    {
        return SmsCampaignRecipientDomainObject::class;
    }
}
