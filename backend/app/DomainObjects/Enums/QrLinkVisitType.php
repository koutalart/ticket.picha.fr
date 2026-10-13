<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects\Enums;

enum QrLinkVisitType: string
{
    use BaseEnum;

    case SCAN = 'SCAN';
    case CLICK = 'CLICK';
}
