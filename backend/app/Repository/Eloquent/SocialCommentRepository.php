<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\Generated\SocialCommentDomainObjectAbstract;
use HiEvents\DomainObjects\SocialCommentDomainObject;
use HiEvents\Models\SocialComment;
use HiEvents\Repository\Interfaces\SocialCommentRepositoryInterface;
use Illuminate\Support\Collection;

/**
 * @extends BaseRepository<SocialCommentDomainObject>
 */
class SocialCommentRepository extends BaseRepository implements SocialCommentRepositoryInterface
{
    protected function getModel(): string
    {
        return SocialComment::class;
    }

    public function getDomainObject(): string
    {
        return SocialCommentDomainObject::class;
    }

    public function upsertFromNetwork(string $network, string $externalId, array $attributes): SocialCommentDomainObject
    {
        $model = SocialComment::query()->updateOrCreate([
            SocialCommentDomainObjectAbstract::NETWORK => $network,
            SocialCommentDomainObjectAbstract::EXTERNAL_ID => $externalId,
        ], $attributes);

        return $this->handleSingleResult($model);
    }

    public function deleteFromNetwork(string $network, string $externalId): void
    {
        SocialComment::query()
            ->where(SocialCommentDomainObjectAbstract::NETWORK, $network)
            ->where(function ($query) use ($externalId) {
                $query->where(SocialCommentDomainObjectAbstract::EXTERNAL_ID, $externalId)
                    ->orWhere(SocialCommentDomainObjectAbstract::PARENT_EXTERNAL_ID, $externalId);
            })
            ->delete();
    }

    public function findForFeedItem(int $feedItemId): Collection
    {
        return $this->handleResults(SocialComment::query()
            ->where(SocialCommentDomainObjectAbstract::SOCIAL_FEED_ITEM_ID, $feedItemId)
            ->orderBy(SocialCommentDomainObjectAbstract::COMMENTED_AT)
            ->orderBy(SocialCommentDomainObjectAbstract::ID)
            ->get());
    }
}
