<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects\Status;

use HiEvents\DomainObjects\Enums\BaseEnum;

enum SmsCampaignRecipientStatus: string
{
    use BaseEnum;

    case PENDING = 'PENDING';
    case SCHEDULED = 'SCHEDULED';
    case SENT = 'SENT';
    case DELIVERED = 'DELIVERED';
    case FAILED = 'FAILED';
    case EXPIRED = 'EXPIRED';

    public function isFinal(): bool
    {
        return in_array($this, [self::DELIVERED, self::FAILED, self::EXPIRED], true);
    }

    public static function fromProvider(?string $status): ?self
    {
        return self::tryFrom(strtoupper(trim((string) $status)));
    }
}
