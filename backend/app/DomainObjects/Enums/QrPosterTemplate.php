<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects\Enums;

enum QrPosterTemplate: string
{
    use BaseEnum;

    case NONE = 'none';
    case STORY = 'story';
    case POSTER = 'poster';
    case SQUARE = 'square';
    case CUSTOM = 'custom';
}
