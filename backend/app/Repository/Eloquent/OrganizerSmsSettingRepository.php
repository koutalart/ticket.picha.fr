<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\OrganizerSmsSettingDomainObject;
use HiEvents\Models\OrganizerSmsSetting;
use HiEvents\Repository\Interfaces\OrganizerSmsSettingRepositoryInterface;

/**
 * @extends BaseRepository<OrganizerSmsSettingDomainObject>
 */
class OrganizerSmsSettingRepository extends BaseRepository implements OrganizerSmsSettingRepositoryInterface
{
    protected function getModel(): string
    {
        return OrganizerSmsSetting::class;
    }

    public function getDomainObject(): string
    {
        return OrganizerSmsSettingDomainObject::class;
    }
}
