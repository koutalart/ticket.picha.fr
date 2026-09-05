<?php

namespace HiEvents\DomainObjects\Status;

use HiEvents\DomainObjects\Enums\BaseEnum;

enum BoxOfficeSaleStatus
{
    use BaseEnum;

    case PENDING;
    case COMPLETED;
}
