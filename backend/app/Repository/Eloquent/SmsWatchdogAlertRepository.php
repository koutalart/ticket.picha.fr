<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\SmsWatchdogAlertDomainObject;
use HiEvents\Models\SmsWatchdogAlert;
use HiEvents\Repository\Interfaces\SmsWatchdogAlertRepositoryInterface;

/**
 * @extends BaseRepository<SmsWatchdogAlertDomainObject>
 */
class SmsWatchdogAlertRepository extends BaseRepository implements SmsWatchdogAlertRepositoryInterface
{
    protected function getModel(): string
    {
        return SmsWatchdogAlert::class;
    }

    public function getDomainObject(): string
    {
        return SmsWatchdogAlertDomainObject::class;
    }
}
