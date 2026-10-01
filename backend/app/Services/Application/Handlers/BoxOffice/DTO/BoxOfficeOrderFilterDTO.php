<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\BoxOffice\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class BoxOfficeOrderFilterDTO extends BaseDataObject
{
    public const CHANNEL_ONLINE = 'ONLINE';

    public const CHANNEL_BOX_OFFICE = 'BOX_OFFICE';

    public function __construct(
        public readonly ?string $query = null,
        public readonly ?string $channel = null,
        public readonly ?int $agent_user_id = null,
        public readonly bool $not_checked_in = false,
        public readonly bool $cancelled = false,
        public readonly int $page = 1,
        public readonly int $per_page = 25,
    ) {}
}
