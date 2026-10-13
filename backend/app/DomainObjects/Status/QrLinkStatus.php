<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects\Status;

use HiEvents\DomainObjects\Enums\BaseEnum;

enum QrLinkStatus: string
{
    use BaseEnum;

    case ACTIVE = 'ACTIVE';
    case PAUSED = 'PAUSED';
}
