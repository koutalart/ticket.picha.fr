<?php

namespace HiEvents\DomainObjects\Enums;

enum EventCategory: string
{
    use BaseEnum;

    // Community
    case SOCIAL = 'SOCIAL';
    case FOOD_DRINK = 'FOOD_DRINK';
    case CHARITY = 'CHARITY';

    // Creative & Culture
    case MUSIC = 'MUSIC';
    case ART = 'ART';
    case COMEDY = 'COMEDY';
    case THEATER = 'THEATER';

    // Professional & Learning
    case BUSINESS = 'BUSINESS';
    case TECH = 'TECH';
    case EDUCATION = 'EDUCATION';
    case WORKSHOP = 'WORKSHOP';

    // Leisure & Nightlife
    case SPORTS = 'SPORTS';
    case FESTIVAL = 'FESTIVAL';
    case NIGHTLIFE = 'NIGHTLIFE';

    // PICHA Ticket categories
    case OPEN_HOUSE = 'OPEN_HOUSE';
    case SEMINAR = 'SEMINAR';
    case INTERNAL_EVENT = 'INTERNAL_EVENT';
    case CONFERENCE = 'CONFERENCE';
    case GENERAL_ASSEMBLY = 'GENERAL_ASSEMBLY';
    case AWARDS = 'AWARDS';
    case CULTURE = 'CULTURE';

    // Catch-all
    case OTHER = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::SOCIAL => __('Social'),
            self::FOOD_DRINK => __('Food & Drink'),
            self::CHARITY => __('Charity'),
            self::MUSIC => __('Music'),
            self::ART => __('Art'),
            self::COMEDY => __('Comedy'),
            self::THEATER => __('Theater'),
            self::BUSINESS => __('Business'),
            self::TECH => __('Tech'),
            self::EDUCATION => __('Education'),
            self::WORKSHOP => __('Workshop'),
            self::SPORTS => __('Sports'),
            self::FESTIVAL => __('Festival'),
            self::NIGHTLIFE => __('Nightlife'),
            self::OPEN_HOUSE => __('Open House'),
            self::SEMINAR => __('Seminar'),
            self::INTERNAL_EVENT => __('Internal Event'),
            self::CONFERENCE => __('Conference'),
            self::GENERAL_ASSEMBLY => __('General Assembly'),
            self::AWARDS => __('Awards'),
            self::CULTURE => __('Culture'),
            self::OTHER => __('Other'),
        };
    }

    public function emoji(): string
    {
        return match ($this) {
            self::SOCIAL => '🤝',
            self::FOOD_DRINK => '🍽️',
            self::CHARITY => '🎗️',
            self::MUSIC => '🎵',
            self::ART => '🎨',
            self::COMEDY => '😂',
            self::THEATER => '🎭',
            self::BUSINESS => '💼',
            self::TECH => '💻',
            self::EDUCATION => '📚',
            self::WORKSHOP => '🛠️',
            self::SPORTS => '⚽',
            self::FESTIVAL => '🎉',
            self::NIGHTLIFE => '🪩',
            self::OPEN_HOUSE => '🚪',
            self::SEMINAR => '💼',
            self::INTERNAL_EVENT => '🏢',
            self::CONFERENCE => '🎤',
            self::GENERAL_ASSEMBLY => '🏛️',
            self::AWARDS => '🏆',
            self::CULTURE => '🎭',
            self::OTHER => '📝',
        };
    }
}
