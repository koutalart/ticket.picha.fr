<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects\Enums;

enum OrganizerTeamContactRole: string
{
    use BaseEnum;

    case ORGANIZER = 'ORGANIZER';
    case COPY = 'COPY';
}
