<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects\Enums;

enum EventAssistantFactSource: string
{
    use BaseEnum;

    case ORGANIZER = 'ORGANIZER';
    case ASSISTANT = 'ASSISTANT';
    case ADMIN = 'ADMIN';
    case CHANGE = 'CHANGE';
}
