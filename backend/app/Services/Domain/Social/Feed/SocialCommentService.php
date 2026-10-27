<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Social\Feed;

use Carbon\Carbon;
use HiEvents\DomainObjects\Enums\SocialNetwork;
use HiEvents\DomainObjects\Generated\SocialCommentDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SocialFeedItemDomainObjectAbstract;
use HiEvents\DomainObjects\SocialCommentDomainObject;
use HiEvents\DomainObjects\SocialFeedItemDomainObject;
use HiEvents\Exceptions\MetaGraphApiException;
use HiEvents\Exceptions\SocialCommentNotFoundException;
use HiEvents\Exceptions\SocialCommentReplyFailedException;
use HiEvents\Repository\Interfaces\SocialCommentRepositoryInterface;
use HiEvents\Repository\Interfaces\SocialFeedItemRepositoryInterface;
use HiEvents\Services\Domain\Social\MetaConnectionService;
use HiEvents\Services\Infrastructure\Meta\MetaGraphClient;
use Illuminate\Support\Collection;

/**
 * Comments of the organizer's posts. A reply only leaves when a person sends it.
 */
class SocialCommentService
{
    public const MAX_REPLY_LENGTH = 2200;

    public function __construct(
        private readonly SocialFeedItemRepositoryInterface $feedItemRepository,
        private readonly SocialCommentRepositoryInterface $commentRepository,
        private readonly SocialFeedSyncService $syncService,
        private readonly MetaConnectionService $connectionService,
        private readonly MetaGraphClient $client,
    ) {}

    /**
     * @return Collection<int, SocialCommentDomainObject>
     *
     * @throws SocialCommentNotFoundException
     */
    public function commentsOf(int $organizerId, int $feedItemId): Collection
    {
        $item = $this->findItem($organizerId, $feedItemId);
        $this->syncService->syncCommentsIfStale($item);

        return $this->commentRepository->findForFeedItem($item->getId());
    }

    /**
     * @throws SocialCommentNotFoundException
     */
    public function findComment(int $organizerId, int $commentId): SocialCommentDomainObject
    {
        /** @var SocialCommentDomainObject|null $comment */
        $comment = $this->commentRepository->findFirstWhere([
            SocialCommentDomainObjectAbstract::ID => $commentId,
            SocialCommentDomainObjectAbstract::ORGANIZER_ID => $organizerId,
        ]);

        if ($comment === null) {
            throw new SocialCommentNotFoundException(__('Comment not found.'));
        }

        return $comment;
    }

    /**
     * @throws SocialCommentNotFoundException
     */
    public function findItem(int $organizerId, int $feedItemId): SocialFeedItemDomainObject
    {
        /** @var SocialFeedItemDomainObject|null $item */
        $item = $this->feedItemRepository->findFirstWhere([
            SocialFeedItemDomainObjectAbstract::ID => $feedItemId,
            SocialFeedItemDomainObjectAbstract::ORGANIZER_ID => $organizerId,
        ]);

        if ($item === null) {
            throw new SocialCommentNotFoundException(__('Post not found.'));
        }

        return $item;
    }

    /**
     * Instagram and Facebook keep one level of replies: answering a reply answers its thread.
     *
     * @throws SocialCommentNotFoundException
     * @throws SocialCommentReplyFailedException
     */
    public function reply(int $organizerId, int $commentId, int $userId, string $message): SocialCommentDomainObject
    {
        $comment = $this->findComment($organizerId, $commentId);
        $message = trim(str_replace("\r\n", "\n", strip_tags($message)));

        if ($message === '' || mb_strlen($message) > self::MAX_REPLY_LENGTH) {
            throw new SocialCommentReplyFailedException(__('The reply must contain between 1 and :max characters.', ['max' => self::MAX_REPLY_LENGTH]));
        }

        $connection = $this->syncService->activeConnection($organizerId);

        if ($connection === null) {
            throw new SocialCommentReplyFailedException(__('The Facebook connection must be renewed in the organizer settings.'));
        }

        $threadId = $comment->getParentExternalId() ?? $comment->getExternalId();
        $isInstagram = $comment->getNetwork() === SocialNetwork::INSTAGRAM->name;

        try {
            $replyId = $isInstagram
                ? $this->client->replyToInstagramComment($threadId, $connection->getPageAccessToken(), $message)
                : $this->client->replyToFacebookComment($threadId, $connection->getPageAccessToken(), $message);
        } catch (MetaGraphApiException $exception) {
            if ($exception->isTokenInvalid()) {
                $this->connectionService->markNeedsReconnect($connection->getId(), $exception->getMessage());
            }

            throw new SocialCommentReplyFailedException(__('The reply was not sent: :message', ['message' => $exception->getMessage()]));
        }

        return $this->commentRepository->upsertFromNetwork($comment->getNetwork(), $replyId, [
            SocialCommentDomainObjectAbstract::ORGANIZER_ID => $organizerId,
            SocialCommentDomainObjectAbstract::SOCIAL_FEED_ITEM_ID => $comment->getSocialFeedItemId(),
            SocialCommentDomainObjectAbstract::PARENT_EXTERNAL_ID => $threadId,
            SocialCommentDomainObjectAbstract::AUTHOR_ID => $isInstagram ? $connection->getInstagramAccountId() : $connection->getFacebookPageId(),
            SocialCommentDomainObjectAbstract::AUTHOR_NAME => $isInstagram ? $connection->getInstagramUsername() : $connection->getFacebookPageName(),
            SocialCommentDomainObjectAbstract::MESSAGE => $message,
            SocialCommentDomainObjectAbstract::IS_FROM_PAGE => true,
            SocialCommentDomainObjectAbstract::REPLIED_BY_USER_ID => $userId,
            SocialCommentDomainObjectAbstract::COMMENTED_AT => Carbon::now(),
        ]);
    }
}
