<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects\Enums;

enum SmsCancellationSource: string
{
    use BaseEnum;

    case PARTICIPANT = 'PARTICIPANT';
    case ORGANIZER = 'ORGANIZER';
}
