<?php

namespace HiEvents\DomainObjects\Enums;

enum OrganizerHomepageTemplate: string
{
    use BaseEnum;

    case DEFAULT = 'DEFAULT';
    case POSTER = 'POSTER';
}
