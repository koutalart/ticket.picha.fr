<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\SmsUnattributedMessageDomainObject;
use HiEvents\Models\SmsUnattributedMessage;
use HiEvents\Repository\Interfaces\SmsUnattributedMessageRepositoryInterface;

/**
 * @extends BaseRepository<SmsUnattributedMessageDomainObject>
 */
class SmsUnattributedMessageRepository extends BaseRepository implements SmsUnattributedMessageRepositoryInterface
{
    protected function getModel(): string
    {
        return SmsUnattributedMessage::class;
    }

    public function getDomainObject(): string
    {
        return SmsUnattributedMessageDomainObject::class;
    }
}
