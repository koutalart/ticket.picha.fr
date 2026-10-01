<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\BoxOffice;

use Carbon\Carbon;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Status\EventStatus;

class BoxOfficeEventAvailabilityService
{
    public function isSellable(EventDomainObject $event): bool
    {
        if ($event->getStatus() !== EventStatus::LIVE->name) {
            return false;
        }

        return $event->getEndDate() === null
            || Carbon::parse($event->getEndDate(), 'UTC')->isFuture();
    }
}
