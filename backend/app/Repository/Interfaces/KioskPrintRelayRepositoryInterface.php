<?php

declare(strict_types=1);

namespace HiEvents\Repository\Interfaces;

use HiEvents\DomainObjects\KioskPrintRelayDomainObject;

/**
 * @extends RepositoryInterface<KioskPrintRelayDomainObject>
 */
interface KioskPrintRelayRepositoryInterface extends RepositoryInterface
{
    public function replaceTokenHash(int $eventId, string $tokenHash): void;
}
