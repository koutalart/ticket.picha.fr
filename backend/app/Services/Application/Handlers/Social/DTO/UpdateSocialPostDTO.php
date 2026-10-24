<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Social\DTO;

use Carbon\Carbon;
use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\Services\Domain\Social\DTO\SocialMediaItemDTO;

class UpdateSocialPostDTO extends BaseDataObject
{
    /**
     * @param  SocialMediaItemDTO[]|null  $media
     */
    public function __construct(
        public readonly int $accountId,
        public readonly int $organizerId,
        public readonly int $postId,
        public readonly ?string $caption,
        public readonly ?array $media,
        public readonly ?string $link,
        public readonly ?Carbon $scheduledAt,
    ) {}
}
