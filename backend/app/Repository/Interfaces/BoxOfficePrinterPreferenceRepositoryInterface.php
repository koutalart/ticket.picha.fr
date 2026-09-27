<?php

declare(strict_types=1);

namespace HiEvents\Repository\Interfaces;

use HiEvents\DomainObjects\BoxOfficePrinterPreferenceDomainObject;

/**
 * @extends RepositoryInterface<BoxOfficePrinterPreferenceDomainObject>
 */
interface BoxOfficePrinterPreferenceRepositoryInterface extends RepositoryInterface
{
    public function rememberHost(int $userId, int $eventId, string $printerHost): void;
}
