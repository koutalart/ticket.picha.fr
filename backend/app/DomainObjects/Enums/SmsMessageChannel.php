<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects\Enums;

/**
 * Where a message of an SMS conversation comes from when it is not an SMS (null channel = SMS).
 */
enum SmsMessageChannel: string
{
    use BaseEnum;

    case WEB_CHAT = 'WEB_CHAT';
}
