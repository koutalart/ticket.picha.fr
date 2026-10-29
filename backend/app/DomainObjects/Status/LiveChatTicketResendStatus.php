<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects\Status;

use HiEvents\DomainObjects\Enums\BaseEnum;

enum LiveChatTicketResendStatus: string
{
    use BaseEnum;

    case CODE_SENT = 'CODE_SENT';
    case EXPIRED = 'EXPIRED';
    case BLOCKED = 'BLOCKED';
    case RESENT = 'RESENT';
}
