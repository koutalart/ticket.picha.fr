<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects\Status;

use HiEvents\DomainObjects\Enums\BaseEnum;

enum SmsPhoneStatus: string
{
    use BaseEnum;

    case ONLINE = 'ONLINE';
    case OFFLINE = 'OFFLINE';
}
