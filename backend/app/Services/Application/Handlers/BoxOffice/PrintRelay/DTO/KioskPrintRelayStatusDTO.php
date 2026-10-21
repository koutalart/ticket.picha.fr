<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\BoxOffice\PrintRelay\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class KioskPrintRelayStatusDTO extends BaseDataObject
{
    public function __construct(
        public readonly bool $configured,
        public readonly ?string $last_seen_at,
        public readonly int $pending_jobs,
    ) {}
}
