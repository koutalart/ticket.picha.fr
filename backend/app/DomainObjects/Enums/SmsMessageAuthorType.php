<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects\Enums;

enum SmsMessageAuthorType: string
{
    use BaseEnum;

    case CONTACT = 'CONTACT';
    case HUMAN = 'HUMAN';
    case AGENT = 'AGENT';
    case CAMPAIGN = 'CAMPAIGN';
    case AUTOMATIC = 'AUTOMATIC';
}
