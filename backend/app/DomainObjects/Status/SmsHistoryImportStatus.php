<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects\Status;

use HiEvents\DomainObjects\Enums\BaseEnum;

enum SmsHistoryImportStatus: string
{
    use BaseEnum;

    case RUNNING = 'RUNNING';
    case COMPLETED = 'COMPLETED';
    case FAILED = 'FAILED';
}
