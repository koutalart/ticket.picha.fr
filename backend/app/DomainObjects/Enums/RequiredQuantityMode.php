<?php

namespace HiEvents\DomainObjects\Enums;

enum RequiredQuantityMode
{
    use BaseEnum;

    case PER_ORDER;
    case PER_TICKET;
}
