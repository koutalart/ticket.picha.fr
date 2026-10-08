<?php

namespace HiEvents\DomainObjects\Enums;

enum DemoRequestEventType: string
{
    use BaseEnum;

    case OPEN_HOUSE = 'OPEN_HOUSE';
    case SEMINAR = 'SEMINAR';
    case INTERNAL_EVENT = 'INTERNAL_EVENT';
    case CONFERENCE = 'CONFERENCE';
    case GENERAL_ASSEMBLY = 'GENERAL_ASSEMBLY';
    case FESTIVAL = 'FESTIVAL';
    case WORKSHOP = 'WORKSHOP';
    case AWARDS = 'AWARDS';
    case MUSIC = 'MUSIC';
    case SPORTS = 'SPORTS';
    case CULTURE = 'CULTURE';
    case CHARITY = 'CHARITY';
    case OTHER = 'OTHER';

    public function label(): string
    {
        return EventCategory::from($this->value)->label();
    }
}
