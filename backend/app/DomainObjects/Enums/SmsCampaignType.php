<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects\Enums;

enum SmsCampaignType: string
{
    use BaseEnum;

    case IMPORTED = 'IMPORTED';
    case REMINDER = 'REMINDER';
}
