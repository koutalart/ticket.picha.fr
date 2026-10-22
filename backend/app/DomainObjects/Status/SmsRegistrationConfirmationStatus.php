<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects\Status;

use HiEvents\DomainObjects\Enums\BaseEnum;

enum SmsRegistrationConfirmationStatus: string
{
    use BaseEnum;

    case SENDING = 'SENDING';
    case SENT = 'SENT';
    case FAILED = 'FAILED';
}
