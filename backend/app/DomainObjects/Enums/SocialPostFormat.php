<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects\Enums;

enum SocialPostFormat: string
{
    use BaseEnum;

    case TEXT = 'TEXT';
    case IMAGE = 'IMAGE';
    case CAROUSEL = 'CAROUSEL';
    case REEL = 'REEL';
    case STORY = 'STORY';
}
