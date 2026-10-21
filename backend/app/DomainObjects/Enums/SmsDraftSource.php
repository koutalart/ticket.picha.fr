<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects\Enums;

enum SmsDraftSource: string
{
    use BaseEnum;

    case AGENT = 'AGENT';
}
