<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects\Enums;

enum AttendanceAccessChannel: string
{
    use BaseEnum;

    case EMAIL = 'EMAIL';
    case PHONE = 'PHONE';
}
