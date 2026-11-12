<?php

namespace HiEvents\DomainObjects\Status;

use HiEvents\DomainObjects\Enums\BaseEnum;

enum EventViewerStatus
{
    use BaseEnum;

    case ACTIVE;
    case REVOKED;
}
