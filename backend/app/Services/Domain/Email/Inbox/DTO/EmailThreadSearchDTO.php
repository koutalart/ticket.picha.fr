<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Email\Inbox\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class EmailThreadSearchDTO extends BaseDataObject
{
    public const FILTER_ALL = 'all';

    public const FILTER_UNREAD = 'unread';

    public const FILTER_NEEDS_HUMAN = 'needs_human';

    public const FILTER_FAILED = 'failed';

    public const FILTERS = [
        self::FILTER_ALL,
        self::FILTER_UNREAD,
        self::FILTER_NEEDS_HUMAN,
        self::FILTER_FAILED,
    ];

    public function __construct(
        public readonly ?string $query = null,
        public readonly string $filter = self::FILTER_ALL,
        public readonly int $page = 1,
        public readonly int $perPage = 30,
    ) {}
}
