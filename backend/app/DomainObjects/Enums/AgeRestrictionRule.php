<?php

namespace HiEvents\DomainObjects\Enums;

enum AgeRestrictionRule: string
{
    use BaseEnum;

    case NONE = 'NONE';
    case ACCOMPANIED_MINORS = 'ACCOMPANIED_MINORS';
    case MINIMUM_AGE = 'MINIMUM_AGE';
}
