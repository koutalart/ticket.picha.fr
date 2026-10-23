<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\Generated\OrganizerSmsSettingDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerSmsSettingDomainObject;
use HiEvents\Models\OrganizerSmsSetting;
use HiEvents\Repository\Interfaces\OrganizerSmsSettingRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

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

    public function findWithSmsLine(int $limit): Collection
    {
        $settings = OrganizerSmsSetting::query()
            ->select([
                OrganizerSmsSettingDomainObjectAbstract::ID,
                OrganizerSmsSettingDomainObjectAbstract::ORGANIZER_ID,
                OrganizerSmsSettingDomainObjectAbstract::ASSISTANT_ENABLED,
                OrganizerSmsSettingDomainObjectAbstract::ASSISTANT_AUTO_SEND,
                OrganizerSmsSettingDomainObjectAbstract::SHARED_LINE_SETTINGS_ID,
            ])
            ->where(fn (Builder $query) => $query
                ->whereNotNull(OrganizerSmsSettingDomainObjectAbstract::SHARED_LINE_SETTINGS_ID)
                ->orWhere(fn (Builder $ownLine) => $ownLine
                    ->whereNotNull(OrganizerSmsSettingDomainObjectAbstract::API_KEY)
                    ->whereNotNull(OrganizerSmsSettingDomainObjectAbstract::FROM_NUMBER)
                    ->where(OrganizerSmsSettingDomainObjectAbstract::FROM_NUMBER, '!=', '')))
            ->orderBy(OrganizerSmsSettingDomainObjectAbstract::ORGANIZER_ID)
            ->limit($limit)
            ->get();

        return $this->handleResults($settings);
    }
}
