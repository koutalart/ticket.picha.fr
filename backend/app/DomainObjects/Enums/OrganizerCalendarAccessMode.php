<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects\Enums;

enum OrganizerCalendarAccessMode: string
{
    use BaseEnum;

    case CODE = 'code';
    case NAME = 'name';
}
