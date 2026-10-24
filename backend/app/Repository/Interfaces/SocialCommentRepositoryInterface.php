<?php

declare(strict_types=1);

namespace HiEvents\Repository\Interfaces;

use HiEvents\DomainObjects\SocialCommentDomainObject;
use Illuminate\Support\Collection;

/**
 * @extends RepositoryInterface<SocialCommentDomainObject>
 */
interface SocialCommentRepositoryInterface extends RepositoryInterface
{
    public function upsertFromNetwork(string $network, string $externalId, array $attributes): SocialCommentDomainObject;

    public function deleteFromNetwork(string $network, string $externalId): void;

    /**
     * Oldest first, replies included.
     *
     * @return Collection<int, SocialCommentDomainObject>
     */
    public function findForFeedItem(int $feedItemId): Collection;
}
