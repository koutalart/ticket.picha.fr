<?php

namespace HiEvents\DomainObjects\Enums;

enum EventPageType: string
{
    use BaseEnum;

    case ARTISTS = 'artistes';
    case PRACTICAL_INFO = 'infos-pratiques';
    case FAQ = 'faq';

    public function contentKey(): string
    {
        return match ($this) {
            self::ARTISTS => 'artists',
            self::PRACTICAL_INFO => 'practical_info',
            self::FAQ => 'faq',
        };
    }
}
