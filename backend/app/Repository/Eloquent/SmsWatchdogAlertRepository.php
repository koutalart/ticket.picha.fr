<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\Generated\SmsWatchdogAlertDomainObjectAbstract;
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

    public function findLastAlertedAt(): ?string
    {
        $alertedAt = SmsWatchdogAlert::query()->max(SmsWatchdogAlertDomainObjectAbstract::ALERTED_AT);

        return $alertedAt === null ? null : (string) $alertedAt;
    }
}
