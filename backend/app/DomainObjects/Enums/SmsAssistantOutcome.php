<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects\Enums;

enum SmsAssistantOutcome: string
{
    use BaseEnum;

    case DRAFT = 'DRAFT';
    case NEEDS_HUMAN = 'NEEDS_HUMAN';
    case FAILED = 'FAILED';
}
