<?php

namespace HiEvents\DomainObjects\Enums;

enum ContactSource
{
    use BaseEnum;

    case ORDER;
    case ATTENDEE;
    case IMPORT;
    case MANUAL;
    case SMS_CAMPAIGN;
}
