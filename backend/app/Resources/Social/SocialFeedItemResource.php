<?php

declare(strict_types=1);

namespace HiEvents\Resources\Social;

use HiEvents\DomainObjects\SocialFeedItemDomainObject;
use HiEvents\Resources\BaseResource;

/**
 * A post read on Instagram or Facebook.
 *
 * @mixin SocialFeedItemDomainObject
 */
class SocialFeedItemResource extends BaseResource
{
    public function toArray($request): array
    {
        $media = $this->getChildren();

        if (is_string($media)) {
            $media = json_decode($media, true);
        }

        return [
            'id' => $this->getId(),
            'network' => $this->getNetwork(),
            'format' => $this->format(),
            'caption' => $this->getCaption(),
            'media' => is_array($media) ? array_values($media) : [],
            'permalink' => $this->getPermalink(),
            'published_at' => $this->getPublishedAt(),
            'like_count' => $this->getLikeCount(),
            'comments_count' => $this->getCommentsCount(),
            'social_post_id' => $this->getSocialPostId(),
        ];
    }

    private function format(): string
    {
        return match (true) {
            $this->getMediaProductType() === 'REELS' => 'REEL',
            $this->getMediaProductType() === 'STORY' => 'STORY',
            $this->getMediaType() === 'CAROUSEL_ALBUM' => 'CAROUSEL',
            $this->getMediaType() === 'VIDEO' => 'VIDEO',
            $this->getMediaType() === 'TEXT' => 'TEXT',
            default => 'IMAGE',
        };
    }
}
