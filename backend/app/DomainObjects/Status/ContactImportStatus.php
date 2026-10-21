<?php

namespace HiEvents\DomainObjects\Status;

use HiEvents\DomainObjects\Enums\BaseEnum;

enum ContactImportStatus
{
    use BaseEnum;

    case PENDING;
    case COMPLETED;
    case FAILED;
    case REVERTED;
}
