<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects\Enums;

enum RefundDecision: string
{
    use BaseEnum;

    case APPROVED = 'APPROVED';
    case REFUSED = 'REFUSED';
}
