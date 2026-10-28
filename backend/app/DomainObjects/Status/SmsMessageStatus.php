<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects\Status;

use HiEvents\DomainObjects\Enums\BaseEnum;

enum SmsMessageStatus: string
{
    use BaseEnum;

    case RECEIVED = 'RECEIVED';
    case QUEUED = 'QUEUED';
    case PENDING = 'PENDING';
    case SENT = 'SENT';
    case DELIVERED = 'DELIVERED';
    case FAILED = 'FAILED';
    case EXPIRED = 'EXPIRED';
}
