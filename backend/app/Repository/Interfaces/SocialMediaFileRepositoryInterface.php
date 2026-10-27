<?php

declare(strict_types=1);

namespace HiEvents\Repository\Interfaces;

use Carbon\Carbon;
use HiEvents\DomainObjects\SocialMediaFileDomainObject;
use Illuminate\Support\Collection;

/**
 * @extends RepositoryInterface<SocialMediaFileDomainObject>
 */
interface SocialMediaFileRepositoryInterface extends RepositoryInterface
{
    /**
     * @return Collection<int, SocialMediaFileDomainObject>
     */
    public function findDueForPurge(Carbon $now, int $limit): Collection;

    /**
     * @param  int[]  $ids
     */
    public function setPurgeAfter(array $ids, ?Carbon $purgeAfter): void;
}
