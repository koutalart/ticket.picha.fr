<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\LiveChatRefundRequestLogDomainObject;
use HiEvents\Models\LiveChatRefundRequestLog;
use HiEvents\Repository\Interfaces\LiveChatRefundRequestLogRepositoryInterface;

/**
 * @extends BaseRepository<LiveChatRefundRequestLogDomainObject>
 */
class LiveChatRefundRequestLogRepository extends BaseRepository implements LiveChatRefundRequestLogRepositoryInterface
{
    protected function getModel(): string
    {
        return LiveChatRefundRequestLog::class;
    }

    public function getDomainObject(): string
    {
        return LiveChatRefundRequestLogDomainObject::class;
    }
}
