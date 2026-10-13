<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects\Enums;

enum QrLinkDestinationType: string
{
    use BaseEnum;

    case LINK_PAGE = 'LINK_PAGE';
    case EVENT = 'EVENT';
    case URL = 'URL';
}
