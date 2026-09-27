<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\BoxOfficePrinterPreferenceDomainObject;
use HiEvents\DomainObjects\Generated\BoxOfficePrinterPreferenceDomainObjectAbstract;
use HiEvents\Models\BoxOfficePrinterPreference;
use HiEvents\Repository\Interfaces\BoxOfficePrinterPreferenceRepositoryInterface;

/**
 * @extends BaseRepository<BoxOfficePrinterPreferenceDomainObject>
 */
class BoxOfficePrinterPreferenceRepository extends BaseRepository implements BoxOfficePrinterPreferenceRepositoryInterface
{
    protected function getModel(): string
    {
        return BoxOfficePrinterPreference::class;
    }

    public function getDomainObject(): string
    {
        return BoxOfficePrinterPreferenceDomainObject::class;
    }

    public function rememberHost(int $userId, int $eventId, string $printerHost): void
    {
        $now = now();

        BoxOfficePrinterPreference::query()->upsert(
            [[
                BoxOfficePrinterPreferenceDomainObjectAbstract::USER_ID => $userId,
                BoxOfficePrinterPreferenceDomainObjectAbstract::EVENT_ID => $eventId,
                BoxOfficePrinterPreferenceDomainObjectAbstract::PRINTER_HOST => $printerHost,
                BoxOfficePrinterPreferenceDomainObjectAbstract::CREATED_AT => $now,
                BoxOfficePrinterPreferenceDomainObjectAbstract::UPDATED_AT => $now,
            ]],
            [
                BoxOfficePrinterPreferenceDomainObjectAbstract::USER_ID,
                BoxOfficePrinterPreferenceDomainObjectAbstract::EVENT_ID,
            ],
            [
                BoxOfficePrinterPreferenceDomainObjectAbstract::PRINTER_HOST,
                BoxOfficePrinterPreferenceDomainObjectAbstract::UPDATED_AT,
            ],
        );
    }
}
