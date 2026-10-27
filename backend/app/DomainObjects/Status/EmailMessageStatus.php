<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects\Status;

use HiEvents\DomainObjects\Enums\BaseEnum;

enum EmailMessageStatus: string
{
    use BaseEnum;

    case SENDING = 'SENDING';
    case SENT = 'SENT';
    case DELAYED = 'DELAYED';
    case DELIVERED = 'DELIVERED';
    case OPENED = 'OPENED';
    case BOUNCED = 'BOUNCED';
    case COMPLAINED = 'COMPLAINED';
    case FAILED = 'FAILED';
    case RECEIVED = 'RECEIVED';

    public function rank(): int
    {
        return match ($this) {
            self::SENDING => 0,
            self::SENT, self::DELAYED => 1,
            self::DELIVERED => 2,
            self::OPENED => 3,
            self::BOUNCED, self::COMPLAINED, self::FAILED, self::RECEIVED => 4,
        };
    }

    public function isFailure(): bool
    {
        return in_array($this, [self::BOUNCED, self::COMPLAINED, self::FAILED], true);
    }
}
