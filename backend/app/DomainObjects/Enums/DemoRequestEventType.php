<?php

namespace HiEvents\DomainObjects\Enums;

enum DemoRequestEventType: string
{
    use BaseEnum;

    case CONFERENCE = 'CONFERENCE';
    case SEMINAR = 'SEMINAR';
    case GENERAL_ASSEMBLY = 'GENERAL_ASSEMBLY';
    case TRADE_SHOW = 'TRADE_SHOW';
    case CEREMONY = 'CEREMONY';
    case INTERNAL_EVENT = 'INTERNAL_EVENT';
    case OTHER = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::CONFERENCE => __('Conference or symposium'),
            self::SEMINAR => __('Seminar or convention'),
            self::GENERAL_ASSEMBLY => __('General assembly'),
            self::TRADE_SHOW => __('Trade show or open day'),
            self::CEREMONY => __('Ceremony, gala or award night'),
            self::INTERNAL_EVENT => __('Internal event'),
            self::OTHER => __('Other'),
        };
    }
}
