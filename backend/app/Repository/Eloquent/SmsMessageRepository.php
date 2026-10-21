<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\SmsMessageDomainObject;
use HiEvents\Models\SmsMessage;
use HiEvents\Repository\Interfaces\SmsMessageRepositoryInterface;

/**
 * @extends BaseRepository<SmsMessageDomainObject>
 */
class SmsMessageRepository extends BaseRepository implements SmsMessageRepositoryInterface
{
    protected function getModel(): string
    {
        return SmsMessage::class;
    }

    public function getDomainObject(): string
    {
        return SmsMessageDomainObject::class;
    }
}
