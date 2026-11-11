<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects\Status;

use HiEvents\DomainObjects\Enums\BaseEnum;

enum AssistantActionStatus: string
{
    use BaseEnum;

    case AWAITING_CONFIRMATION = 'AWAITING_CONFIRMATION';
    case CONFIRMED = 'CONFIRMED';
    case EXECUTED = 'EXECUTED';
    case FAILED = 'FAILED';
    case REFUSED = 'REFUSED';
}
