<?php

namespace HiEvents\DomainObjects\Enums;

enum SchemaEventType: string
{
    use BaseEnum;

    case EVENT = 'Event';
    case MUSIC_EVENT = 'MusicEvent';
    case THEATER_EVENT = 'TheaterEvent';
    case COMEDY_EVENT = 'ComedyEvent';
    case SPORTS_EVENT = 'SportsEvent';
    case FESTIVAL = 'Festival';
    case BUSINESS_EVENT = 'BusinessEvent';
    case EDUCATION_EVENT = 'EducationEvent';
    case SOCIAL_EVENT = 'SocialEvent';
}
