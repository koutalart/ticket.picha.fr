<?php

declare(strict_types=1);

namespace HiEvents\Services\Infrastructure\Meta\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class MetaPageDTO extends BaseDataObject
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $accessToken,
        public readonly ?string $instagramAccountId = null,
        public readonly ?string $instagramUsername = null,
        public readonly array $tasks = [],
    ) {}

    public function canCreateContent(): bool
    {
        return $this->tasks === [] || in_array('CREATE_CONTENT', $this->tasks, true);
    }
}
