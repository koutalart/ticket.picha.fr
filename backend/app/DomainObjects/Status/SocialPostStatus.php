<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects\Status;

use HiEvents\DomainObjects\Enums\BaseEnum;

enum SocialPostStatus: string
{
    use BaseEnum;

    case DRAFT = 'DRAFT';
    case PENDING_APPROVAL = 'PENDING_APPROVAL';
    case SCHEDULED = 'SCHEDULED';
    case PUBLISHING = 'PUBLISHING';
    case PUBLISHED = 'PUBLISHED';
    case FAILED = 'FAILED';
    case CANCELLED = 'CANCELLED';
}
