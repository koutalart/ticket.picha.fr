<?php

declare(strict_types=1);

namespace HiEvents\Repository\Interfaces;

use HiEvents\DomainObjects\SmsWatchdogAlertDomainObject;

/**
 * @extends RepositoryInterface<SmsWatchdogAlertDomainObject>
 */
interface SmsWatchdogAlertRepositoryInterface extends RepositoryInterface
{
    /**
     * When the last alert e-mail went out, null when none did.
     */
    public function findLastAlertedAt(): ?string;
}
