<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class SmsWatchdogDetectionDTO extends BaseDataObject
{
    /**
     * @param  array<int, SmsWatchdogAnomalyDTO>  $anomalies
     * @param  array<int, array<int, string>>  $completeChecks  anomaly types fully checked, by organizer id
     *                                                          (a check cut by its limit cannot tell what got resolved)
     */
    public function __construct(
        public readonly array $anomalies = [],
        public readonly array $completeChecks = [],
    ) {}
}
