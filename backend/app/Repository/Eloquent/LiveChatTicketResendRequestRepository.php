<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\LiveChatTicketResendRequestDomainObject;
use HiEvents\Models\LiveChatTicketResendRequest;
use HiEvents\Repository\Interfaces\LiveChatTicketResendRequestRepositoryInterface;

/**
 * @extends BaseRepository<LiveChatTicketResendRequestDomainObject>
 */
class LiveChatTicketResendRequestRepository extends BaseRepository implements LiveChatTicketResendRequestRepositoryInterface
{
    protected function getModel(): string
    {
        return LiveChatTicketResendRequest::class;
    }

    public function getDomainObject(): string
    {
        return LiveChatTicketResendRequestDomainObject::class;
    }
}
