<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects\Enums;

enum OrganizerCalendarConsultationKind: string
{
    use BaseEnum;

    case OPENING = 'ouverture';
    case VISIT = 'visite';
}
