<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects\Enums;

enum SmsMessageDirection: string
{
    use BaseEnum;

    case INBOUND = 'INBOUND';
    case OUTBOUND = 'OUTBOUND';
}
