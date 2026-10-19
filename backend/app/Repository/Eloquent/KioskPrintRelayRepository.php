<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\Generated\KioskPrintRelayDomainObjectAbstract;
use HiEvents\DomainObjects\KioskPrintRelayDomainObject;
use HiEvents\Models\KioskPrintRelay;
use HiEvents\Repository\Interfaces\KioskPrintRelayRepositoryInterface;

/**
 * @extends BaseRepository<KioskPrintRelayDomainObject>
 */
class KioskPrintRelayRepository extends BaseRepository implements KioskPrintRelayRepositoryInterface
{
    protected function getModel(): string
    {
        return KioskPrintRelay::class;
    }

    public function getDomainObject(): string
    {
        return KioskPrintRelayDomainObject::class;
    }

    public function replaceTokenHash(int $eventId, string $tokenHash): void
    {
        $now = now();

        KioskPrintRelay::query()->upsert(
            [[
                KioskPrintRelayDomainObjectAbstract::EVENT_ID => $eventId,
                KioskPrintRelayDomainObjectAbstract::TOKEN_HASH => $tokenHash,
                KioskPrintRelayDomainObjectAbstract::LAST_SEEN_AT => null,
                KioskPrintRelayDomainObjectAbstract::CREATED_AT => $now,
                KioskPrintRelayDomainObjectAbstract::UPDATED_AT => $now,
            ]],
            [KioskPrintRelayDomainObjectAbstract::EVENT_ID],
            [
                KioskPrintRelayDomainObjectAbstract::TOKEN_HASH,
                KioskPrintRelayDomainObjectAbstract::LAST_SEEN_AT,
                KioskPrintRelayDomainObjectAbstract::UPDATED_AT,
            ],
        );
    }
}
