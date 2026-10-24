<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Social;

use HiEvents\Exceptions\SocialCommentNotFoundException;
use HiEvents\Exceptions\SocialCommentReplyFailedException;
use HiEvents\Services\Domain\Social\Feed\SocialCommentReplySuggester;

class SuggestSocialCommentReplyHandler
{
    public function __construct(
        private readonly SocialCommentReplySuggester $suggester,
    ) {}

    /**
     * @throws SocialCommentNotFoundException
     * @throws SocialCommentReplyFailedException
     */
    public function handle(int $organizerId, int $commentId): string
    {
        return $this->suggester->suggest($organizerId, $commentId);
    }
}
