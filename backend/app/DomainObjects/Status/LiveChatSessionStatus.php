<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects\Status;

use HiEvents\DomainObjects\Enums\BaseEnum;

enum LiveChatSessionStatus: string
{
    use BaseEnum;

    case OPEN = 'OPEN';
    case HANDED_OFF = 'HANDED_OFF';
}
