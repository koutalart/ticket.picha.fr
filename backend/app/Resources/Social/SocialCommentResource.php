<?php

declare(strict_types=1);

namespace HiEvents\Resources\Social;

use HiEvents\DomainObjects\SocialCommentDomainObject;
use HiEvents\Resources\BaseResource;

/**
 * @mixin SocialCommentDomainObject
 */
class SocialCommentResource extends BaseResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->getId(),
            'feed_item_id' => $this->getSocialFeedItemId(),
            'network' => $this->getNetwork(),
            'thread_key' => $this->getParentExternalId() ?? $this->getExternalId(),
            'is_reply' => $this->getParentExternalId() !== null,
            'author_name' => $this->getAuthorName(),
            'message' => $this->getMessage(),
            'like_count' => $this->getLikeCount(),
            'is_from_page' => (bool) $this->getIsFromPage(),
            'replied_by_user_id' => $this->getRepliedByUserId(),
            'commented_at' => $this->getCommentedAt(),
        ];
    }
}
