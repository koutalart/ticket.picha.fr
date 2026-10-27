<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Social;

use HiEvents\DomainObjects\SocialCommentDomainObject;
use HiEvents\Exceptions\SocialCommentNotFoundException;
use HiEvents\Exceptions\SocialCommentReplyFailedException;
use HiEvents\Services\Domain\Social\Feed\SocialCommentService;

class ReplyToSocialCommentHandler
{
    public function __construct(
        private readonly SocialCommentService $commentService,
    ) {}

    /**
     * @throws SocialCommentNotFoundException
     * @throws SocialCommentReplyFailedException
     */
    public function handle(int $organizerId, int $commentId, int $userId, string $message): SocialCommentDomainObject
    {
        return $this->commentService->reply($organizerId, $commentId, $userId, $message);
    }
}
