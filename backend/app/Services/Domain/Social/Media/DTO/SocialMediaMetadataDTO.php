<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Social\Media\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class SocialMediaMetadataDTO extends BaseDataObject
{
    public function __construct(
        public readonly ?int $width = null,
        public readonly ?int $height = null,
        public readonly ?int $durationMs = null,
        public readonly ?string $videoCodec = null,
        public readonly ?string $audioCodec = null,
    ) {}
}
