<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects\Enums;

enum SmsFilePriority: string
{
    use BaseEnum;

    case URGENT = 'urgent';
    case CAMPAIGN = 'campagne';
}
