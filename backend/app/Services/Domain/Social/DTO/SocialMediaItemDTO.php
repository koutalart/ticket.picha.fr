<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Social\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\DomainObjects\Enums\SocialMediaType;

/**
 * A media of a post: a public link, or a file imported in PICHA (fileId), served to Meta through a signed URL.
 */
class SocialMediaItemDTO extends BaseDataObject
{
    public function __construct(
        public readonly string $url,
        public readonly SocialMediaType $type,
        public readonly ?int $fileId = null,
        public readonly ?string $mimeType = null,
        public readonly ?int $width = null,
        public readonly ?int $height = null,
        public readonly ?int $durationMs = null,
    ) {}

    public function isVideo(): bool
    {
        return $this->type === SocialMediaType::VIDEO;
    }

    public function isFile(): bool
    {
        return $this->fileId !== null;
    }

    public function withUrl(string $url): self
    {
        return new self($url, $this->type, $this->fileId, $this->mimeType, $this->width, $this->height, $this->durationMs);
    }
}
