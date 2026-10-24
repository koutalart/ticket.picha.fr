<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Social\DTO;

use Carbon\Carbon;
use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\DomainObjects\Enums\SocialNetwork;
use HiEvents\DomainObjects\Enums\SocialPostFormat;
use HiEvents\DomainObjects\Enums\SocialPostSource;

class CreateSocialPostsDTO extends BaseDataObject
{
    /**
     * @param  SocialNetwork[]  $networks
     * @param  SocialMediaItemDTO[]  $media
     */
    public function __construct(
        public readonly int $accountId,
        public readonly int $organizerId,
        public readonly ?int $userId,
        public readonly array $networks,
        public readonly SocialPostFormat $format,
        public readonly ?string $caption,
        public readonly array $media,
        public readonly ?string $link,
        public readonly ?Carbon $scheduledAt,
        public readonly SocialPostSource $source,
        public readonly bool $asDraft = false,
    ) {}
}
