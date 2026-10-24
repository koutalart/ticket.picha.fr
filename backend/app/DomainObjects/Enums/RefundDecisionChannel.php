<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects\Enums;

enum RefundDecisionChannel: string
{
    use BaseEnum;

    case SMS = 'SMS';
    case LINK = 'LINK';
    case DASHBOARD = 'DASHBOARD';
}
