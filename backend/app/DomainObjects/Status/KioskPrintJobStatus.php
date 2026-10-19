<?php

namespace HiEvents\DomainObjects\Status;

use HiEvents\DomainObjects\Enums\BaseEnum;

enum KioskPrintJobStatus
{
    use BaseEnum;

    case PENDING;
    case SENT;
    case FAILED;
}
