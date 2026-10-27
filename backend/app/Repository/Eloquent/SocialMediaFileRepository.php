<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use Carbon\Carbon;
use HiEvents\DomainObjects\Generated\SocialMediaFileDomainObjectAbstract;
use HiEvents\DomainObjects\SocialMediaFileDomainObject;
use HiEvents\Models\SocialMediaFile;
use HiEvents\Repository\Interfaces\SocialMediaFileRepositoryInterface;
use Illuminate\Support\Collection;

/**
 * @extends BaseRepository<SocialMediaFileDomainObject>
 */
class SocialMediaFileRepository extends BaseRepository implements SocialMediaFileRepositoryInterface
{
    protected function getModel(): string
    {
        return SocialMediaFile::class;
    }

    public function getDomainObject(): string
    {
        return SocialMediaFileDomainObject::class;
    }

    public function findDueForPurge(Carbon $now, int $limit): Collection
    {
        return $this->handleResults(SocialMediaFile::query()
            ->whereNotNull(SocialMediaFileDomainObjectAbstract::PURGE_AFTER)
            ->where(SocialMediaFileDomainObjectAbstract::PURGE_AFTER, '<=', $now)
            ->orderBy(SocialMediaFileDomainObjectAbstract::PURGE_AFTER)
            ->limit($limit)
            ->get());
    }

    public function setPurgeAfter(array $ids, ?Carbon $purgeAfter): void
    {
        if ($ids === []) {
            return;
        }

        SocialMediaFile::query()
            ->whereIn(SocialMediaFileDomainObjectAbstract::ID, $ids)
            ->update([SocialMediaFileDomainObjectAbstract::PURGE_AFTER => $purgeAfter]);
    }
}
