<?php

declare(strict_types=1);

namespace HiEvents\Repository\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

/**
 * Door counters of a check-in list. Paper « Sur place » tickets are not people until scanned:
 * once scanned they count in checkedIn (and paperCheckedIn), never in outside.
 */
class CheckInListLiveStatsDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $checkedIn,
        public readonly int $paperCheckedIn,
        public readonly int $outside,
        public readonly int $paperNotScanned,
    ) {}

    public function expected(): int
    {
        return $this->checkedIn + $this->outside;
    }
}
