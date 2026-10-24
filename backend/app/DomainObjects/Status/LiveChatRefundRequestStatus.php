<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects\Status;

use HiEvents\DomainObjects\Enums\BaseEnum;

enum LiveChatRefundRequestStatus: string
{
    use BaseEnum;

    case REJECTED = 'REJECTED';
    case CODE_SENT = 'CODE_SENT';
    case EXPIRED = 'EXPIRED';
    case BLOCKED = 'BLOCKED';
    case VERIFIED = 'VERIFIED';
    case PROCESSING = 'PROCESSING';
    case REFUNDED = 'REFUNDED';
    case HANDED_OFF = 'HANDED_OFF';
    case FAILED = 'FAILED';
}
