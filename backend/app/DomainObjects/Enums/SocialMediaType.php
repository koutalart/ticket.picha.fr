<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects\Enums;

enum SocialMediaType: string
{
    use BaseEnum;

    case IMAGE = 'IMAGE';
    case VIDEO = 'VIDEO';
}
