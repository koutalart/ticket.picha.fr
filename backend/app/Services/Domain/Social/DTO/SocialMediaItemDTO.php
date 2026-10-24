<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Social\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\DomainObjects\Enums\SocialMediaType;

class SocialMediaItemDTO extends BaseDataObject
{
    public function __construct(
        public readonly string $url,
        public readonly SocialMediaType $type,
    ) {}

    public function isVideo(): bool
    {
        return $this->type === SocialMediaType::VIDEO;
    }
}
