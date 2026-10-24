<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\LiveChatRefundRequestDomainObject;
use HiEvents\Models\LiveChatRefundRequest;
use HiEvents\Repository\Interfaces\LiveChatRefundRequestRepositoryInterface;

/**
 * @extends BaseRepository<LiveChatRefundRequestDomainObject>
 */
class LiveChatRefundRequestRepository extends BaseRepository implements LiveChatRefundRequestRepositoryInterface
{
    protected function getModel(): string
    {
        return LiveChatRefundRequest::class;
    }

    public function getDomainObject(): string
    {
        return LiveChatRefundRequestDomainObject::class;
    }
}
