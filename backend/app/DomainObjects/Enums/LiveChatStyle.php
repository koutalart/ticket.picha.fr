<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects\Enums;

enum LiveChatStyle: string
{
    use BaseEnum;

    case CLASSIC = 'CLASSIC';
    case MESSAGING = 'MESSAGING';
}
