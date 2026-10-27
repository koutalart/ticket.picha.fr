<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects\Enums;

enum EmailMessageDirection: string
{
    use BaseEnum;

    case OUTBOUND = 'OUTBOUND';
    case INBOUND = 'INBOUND';
}
