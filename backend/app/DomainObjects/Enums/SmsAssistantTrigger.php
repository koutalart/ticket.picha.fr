<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects\Enums;

enum SmsAssistantTrigger: string
{
    use BaseEnum;

    case INBOUND = 'INBOUND';
    case REGENERATE = 'REGENERATE';
    case PREVIEW = 'PREVIEW';
}
