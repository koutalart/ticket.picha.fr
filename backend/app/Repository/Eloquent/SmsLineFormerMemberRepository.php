<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\SmsLineFormerMemberDomainObject;
use HiEvents\Models\SmsLineFormerMember;
use HiEvents\Repository\Interfaces\SmsLineFormerMemberRepositoryInterface;

/**
 * @extends BaseRepository<SmsLineFormerMemberDomainObject>
 */
class SmsLineFormerMemberRepository extends BaseRepository implements SmsLineFormerMemberRepositoryInterface
{
    protected function getModel(): string
    {
        return SmsLineFormerMember::class;
    }

    public function getDomainObject(): string
    {
        return SmsLineFormerMemberDomainObject::class;
    }
}
