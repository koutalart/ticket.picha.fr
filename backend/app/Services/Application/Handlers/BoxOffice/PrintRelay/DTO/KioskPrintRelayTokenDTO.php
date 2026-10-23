<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\BoxOffice\PrintRelay\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class KioskPrintRelayTokenDTO extends BaseDataObject
{
    public function __construct(
        public readonly string $relay_token,
        public readonly ?string $last_seen_at,
    ) {}
}
