<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\SmsCheckinWelcomeDomainObject;
use HiEvents\Models\SmsCheckinWelcome;
use HiEvents\Repository\Interfaces\SmsCheckinWelcomeRepositoryInterface;

/**
 * @extends BaseRepository<SmsCheckinWelcomeDomainObject>
 */
class SmsCheckinWelcomeRepository extends BaseRepository implements SmsCheckinWelcomeRepositoryInterface
{
    protected function getModel(): string
    {
        return SmsCheckinWelcome::class;
    }

    public function getDomainObject(): string
    {
        return SmsCheckinWelcomeDomainObject::class;
    }
}
