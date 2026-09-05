<?php

namespace HiEvents\DomainObjects\Enums;

/**
 * D3 (PICHA_BOX_OFFICE_DECISIONS_REQUIRED.md).
 */
enum BoxOfficePaymentMethod
{
    use BaseEnum;

    case CASH;
    case CARD;
    case FREE;
}
