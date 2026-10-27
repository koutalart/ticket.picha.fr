<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Social\Feed\DTO;

use Carbon\Carbon;
use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\DomainObjects\SocialFeedItemDomainObject;
use HiEvents\DomainObjects\SocialPostDomainObject;

/**
 * One entry of the feed: a post prepared in PICHA, a post read on Instagram or Facebook, or both when PICHA published it.
 */
class SocialFeedEntryDTO extends BaseDataObject
{
    public function __construct(
        public readonly Carbon $date,
        public readonly ?SocialPostDomainObject $post = null,
        public readonly ?SocialFeedItemDomainObject $item = null,
    ) {}
}
