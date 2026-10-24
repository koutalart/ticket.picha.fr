<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects\Enums;

enum SocialNetwork: string
{
    use BaseEnum;

    case FACEBOOK = 'FACEBOOK';
    case INSTAGRAM = 'INSTAGRAM';
}
