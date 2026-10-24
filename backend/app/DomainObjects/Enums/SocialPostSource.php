<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects\Enums;

enum SocialPostSource: string
{
    use BaseEnum;

    case PICHA = 'PICHA';
    case ASSISTANT = 'ASSISTANT';
}
