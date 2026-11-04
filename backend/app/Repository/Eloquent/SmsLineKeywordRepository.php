<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\SmsLineKeywordDomainObject;
use HiEvents\Models\SmsLineKeyword;
use HiEvents\Repository\Interfaces\SmsLineKeywordRepositoryInterface;

/**
 * @extends BaseRepository<SmsLineKeywordDomainObject>
 */
class SmsLineKeywordRepository extends BaseRepository implements SmsLineKeywordRepositoryInterface
{
    protected function getModel(): string
    {
        return SmsLineKeyword::class;
    }

    public function getDomainObject(): string
    {
        return SmsLineKeywordDomainObject::class;
    }
}
