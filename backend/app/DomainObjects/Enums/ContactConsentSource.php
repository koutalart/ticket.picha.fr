<?php

namespace HiEvents\DomainObjects\Enums;

enum ContactConsentSource
{
    use BaseEnum;

    case CHECKOUT;
    case IMPORT;
    case MANUAL;
}
