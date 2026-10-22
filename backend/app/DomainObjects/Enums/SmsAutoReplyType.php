<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects\Enums;

enum SmsAutoReplyType: string
{
    use BaseEnum;

    case ANSWER = 'ANSWER';
    case HOLDING = 'HOLDING';
}
