<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects\Status;

use HiEvents\DomainObjects\Enums\BaseEnum;

enum EmailThreadStatus: string
{
    use BaseEnum;

    case OPEN = 'OPEN';
    case NEEDS_HUMAN = 'NEEDS_HUMAN';
    case CLOSED = 'CLOSED';
}
