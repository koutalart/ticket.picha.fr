<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class SmsConversationSearchDTO extends BaseDataObject
{
    public const FILTER_ALL = 'all';

    public const FILTER_UNREAD = 'unread';

    public const FILTER_NEEDS_HUMAN = 'needs_human';

    public function __construct(
        public readonly ?string $query = null,
        public readonly string $filter = self::FILTER_ALL,
        public readonly int $page = 1,
        public readonly int $perPage = 30,
    ) {}
}
