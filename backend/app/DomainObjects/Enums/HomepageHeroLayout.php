<?php

namespace HiEvents\DomainObjects\Enums;

enum HomepageHeroLayout: string
{
    use BaseEnum;

    case CARD = 'CARD';
    case FULLSCREEN = 'FULLSCREEN';
}
