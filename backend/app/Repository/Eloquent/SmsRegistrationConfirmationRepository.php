<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\SmsRegistrationConfirmationDomainObject;
use HiEvents\Models\SmsRegistrationConfirmation;
use HiEvents\Repository\Interfaces\SmsRegistrationConfirmationRepositoryInterface;

/**
 * @extends BaseRepository<SmsRegistrationConfirmationDomainObject>
 */
class SmsRegistrationConfirmationRepository extends BaseRepository implements SmsRegistrationConfirmationRepositoryInterface
{
    protected function getModel(): string
    {
        return SmsRegistrationConfirmation::class;
    }

    public function getDomainObject(): string
    {
        return SmsRegistrationConfirmationDomainObject::class;
    }
}
