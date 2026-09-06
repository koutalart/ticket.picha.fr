<?php

namespace HiEvents\DomainObjects\Status;

use HiEvents\DomainObjects\Enums\BaseEnum;

enum BoxOfficeOperatorStatus
{
    use BaseEnum;

    case ACTIVE;
    case REVOKED;
}
