<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Social;

use HiEvents\DomainObjects\SocialCommentDomainObject;
use HiEvents\Exceptions\SocialCommentNotFoundException;
use HiEvents\Services\Domain\Social\Feed\SocialCommentService;
use Illuminate\Support\Collection;

class GetSocialCommentsHandler
{
    public function __construct(
        private readonly SocialCommentService $commentService,
    ) {}

    /**
     * @return Collection<int, SocialCommentDomainObject>
     *
     * @throws SocialCommentNotFoundException
     */
    public function handle(int $organizerId, int $feedItemId): Collection
    {
        return $this->commentService->commentsOf($organizerId, $feedItemId);
    }
}
