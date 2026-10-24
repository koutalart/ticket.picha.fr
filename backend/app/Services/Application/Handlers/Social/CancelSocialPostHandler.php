<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Social;

use HiEvents\DomainObjects\SocialPostDomainObject;
use HiEvents\Exceptions\SocialPostNotEditableException;
use HiEvents\Services\Domain\Social\SocialPostService;

class CancelSocialPostHandler
{
    public function __construct(
        private readonly SocialPostService $postService,
    ) {}

    /**
     * @throws SocialPostNotEditableException
     */
    public function handle(int $organizerId, int $postId): SocialPostDomainObject
    {
        return $this->postService->cancel($organizerId, $postId);
    }
}
