<?php

namespace HiEvents\DomainObjects\Enums;

enum CustomerAddressForm: string
{
    use BaseEnum;

    case VOUS = 'vous';
    case TU = 'tu';
}
