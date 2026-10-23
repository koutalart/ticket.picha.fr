<?php

namespace HiEvents\Services\Domain\Event\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class EventArtistDTO extends BaseDataObject
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $slug = null,
        public readonly ?string $kind = null,
        public readonly ?int $image_id = null,
        public readonly ?string $photo_url = null,
        public readonly ?string $bio = null,
        public readonly ?string $song = null,
        public readonly ?array $links = null,
    ) {}
}
