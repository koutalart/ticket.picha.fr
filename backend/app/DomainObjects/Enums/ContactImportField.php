<?php

namespace HiEvents\DomainObjects\Enums;

enum ContactImportField
{
    use BaseEnum;

    case FIRST_NAME;
    case LAST_NAME;
    case FULL_NAME;
    case EMAIL;
    case PHONE;
    case TAG;
    case IGNORE;
}
