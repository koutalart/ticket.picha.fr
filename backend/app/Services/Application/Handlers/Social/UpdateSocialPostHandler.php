<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Social;

use HiEvents\DomainObjects\SocialPostDomainObject;
use HiEvents\Exceptions\InvalidSocialPostException;
use HiEvents\Exceptions\SocialPostNotEditableException;
use HiEvents\Services\Application\Handlers\Social\DTO\UpdateSocialPostDTO;
use HiEvents\Services\Domain\Social\SocialPostService;

class UpdateSocialPostHandler
{
    public function __construct(
        private readonly SocialPostService $postService,
    ) {}

    /**
     * @throws InvalidSocialPostException
     * @throws SocialPostNotEditableException
     */
    public function handle(UpdateSocialPostDTO $dto): SocialPostDomainObject
    {
        return $this->postService->update(
            accountId: $dto->accountId,
            organizerId: $dto->organizerId,
            postId: $dto->postId,
            caption: $dto->caption,
            media: $dto->media,
            link: $dto->link,
            scheduledAt: $dto->scheduledAt,
        );
    }
}
