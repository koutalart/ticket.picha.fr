<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use Carbon\Carbon;
use HiEvents\DomainObjects\Generated\SocialPostDomainObjectAbstract;
use HiEvents\DomainObjects\SocialPostDomainObject;
use HiEvents\DomainObjects\Status\SocialPostStatus;
use HiEvents\Models\SocialPost;
use HiEvents\Repository\Interfaces\SocialPostRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * @extends BaseRepository<SocialPostDomainObject>
 */
class SocialPostRepository extends BaseRepository implements SocialPostRepositoryInterface
{
    protected function getModel(): string
    {
        return SocialPost::class;
    }

    public function getDomainObject(): string
    {
        return SocialPostDomainObject::class;
    }

    public function findDueForPublishing(int $limit): Collection
    {
        $posts = SocialPost::query()
            ->where(SocialPostDomainObjectAbstract::STATUS, SocialPostStatus::SCHEDULED->name)
            ->where(SocialPostDomainObjectAbstract::NEXT_ATTEMPT_AT, '<=', now())
            ->orderBy(SocialPostDomainObjectAbstract::NEXT_ATTEMPT_AT)
            ->limit($limit)
            ->get();

        return $this->handleResults($posts);
    }

    public function claimForPublishing(int $postId): bool
    {
        return SocialPost::query()
            ->where(SocialPostDomainObjectAbstract::ID, $postId)
            ->where(SocialPostDomainObjectAbstract::STATUS, SocialPostStatus::SCHEDULED->name)
            ->update([SocialPostDomainObjectAbstract::STATUS => SocialPostStatus::PUBLISHING->name]) === 1;
    }

    public function releaseStalePublishing(Carbon $updatedBefore): int
    {
        return SocialPost::query()
            ->where(SocialPostDomainObjectAbstract::STATUS, SocialPostStatus::PUBLISHING->name)
            ->where(SocialPostDomainObjectAbstract::UPDATED_AT, '<', $updatedBefore)
            ->update([
                SocialPostDomainObjectAbstract::STATUS => SocialPostStatus::SCHEDULED->name,
                SocialPostDomainObjectAbstract::NEXT_ATTEMPT_AT => now(),
            ]);
    }

    public function paginateForOrganizer(int $organizerId, ?string $status, int $perPage): LengthAwarePaginator
    {
        $query = SocialPost::query()
            ->where(SocialPostDomainObjectAbstract::ORGANIZER_ID, $organizerId)
            ->when($status, fn ($query) => $query->where(SocialPostDomainObjectAbstract::STATUS, $status))
            ->orderByRaw('COALESCE(scheduled_at, created_at) DESC')
            ->orderByDesc(SocialPostDomainObjectAbstract::ID);

        return $this->handleResults($query->paginate($perPage));
    }
}
