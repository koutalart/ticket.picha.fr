<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects\Enums;

enum SmsOptOutSource: string
{
    use BaseEnum;

    case KEYWORD = 'KEYWORD';
    case MANUAL = 'MANUAL';
}
