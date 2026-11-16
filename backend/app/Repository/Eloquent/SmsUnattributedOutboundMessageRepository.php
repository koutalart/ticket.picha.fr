<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\SmsUnattributedOutboundMessageDomainObject;
use HiEvents\Models\SmsUnattributedOutboundMessage;
use HiEvents\Repository\Interfaces\SmsUnattributedOutboundMessageRepositoryInterface;

/**
 * @extends BaseRepository<SmsUnattributedOutboundMessageDomainObject>
 */
class SmsUnattributedOutboundMessageRepository extends BaseRepository implements SmsUnattributedOutboundMessageRepositoryInterface
{
    protected function getModel(): string
    {
        return SmsUnattributedOutboundMessage::class;
    }

    public function getDomainObject(): string
    {
        return SmsUnattributedOutboundMessageDomainObject::class;
    }
}
