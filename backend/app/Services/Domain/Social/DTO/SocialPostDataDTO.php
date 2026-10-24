<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Social\DTO;

use Carbon\Carbon;
use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\DomainObjects\Enums\SocialNetwork;
use HiEvents\DomainObjects\Enums\SocialPostFormat;

class SocialPostDataDTO extends BaseDataObject
{
    /**
     * @param  SocialMediaItemDTO[]  $media
     */
    public function __construct(
        public readonly SocialNetwork $network,
        public readonly SocialPostFormat $format,
        public readonly ?string $caption,
        public readonly array $media,
        public readonly ?string $link,
        public readonly ?Carbon $scheduledAt,
    ) {}
}
