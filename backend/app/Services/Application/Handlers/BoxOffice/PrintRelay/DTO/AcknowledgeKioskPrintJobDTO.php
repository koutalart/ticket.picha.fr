<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\BoxOffice\PrintRelay\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\DomainObjects\Status\KioskPrintJobStatus;

class AcknowledgeKioskPrintJobDTO extends BaseDataObject
{
    public function __construct(
        public readonly ?string $relay_token,
        public readonly int $job_id,
        public readonly KioskPrintJobStatus $status,
        public readonly ?string $error = null,
    ) {}
}
