<?php

declare(strict_types=1);

namespace HiEvents\Resources\Social;

use HiEvents\DomainObjects\SocialPostDomainObject;
use HiEvents\Resources\BaseResource;
use HiEvents\Services\Domain\Social\Media\SocialMediaUrlGenerator;

/**
 * @mixin SocialPostDomainObject
 */
class SocialPostResource extends BaseResource
{
    public function toArray($request): array
    {
        $media = $this->getMedia();

        if (is_string($media)) {
            $media = json_decode($media, true);
        }

        return [
            'id' => $this->getId(),
            'organizer_id' => $this->getOrganizerId(),
            'network' => $this->getNetwork(),
            'format' => $this->getFormat(),
            'caption' => $this->getCaption(),
            'media' => is_array($media) ? array_map(fn (array $item) => $this->mediaItem($item), array_values($media)) : [],
            'link' => $this->getLink(),
            'status' => $this->getStatus(),
            'source' => $this->getSource(),
            'scheduled_at' => $this->getScheduledAt(),
            'published_at' => $this->getPublishedAt(),
            'permalink' => $this->getPermalink(),
            'attempts' => $this->getAttempts(),
            'next_attempt_at' => $this->getNextAttemptAt(),
            'last_error' => $this->getLastError(),
            'created_by_user_id' => $this->getCreatedByUserId(),
            'approved_by_user_id' => $this->getApprovedByUserId(),
            'approved_at' => $this->getApprovedAt(),
            'created_at' => $this->getCreatedAt(),
            'updated_at' => $this->getUpdatedAt(),
        ];
    }

    private function mediaItem(array $item): array
    {
        if (! isset($item['file_id'])) {
            return $item;
        }

        return [
            'type' => $item['type'],
            'file_id' => (int) $item['file_id'],
            'url' => app(SocialMediaUrlGenerator::class)->previewUrl($this->getOrganizerId(), (int) $item['file_id']),
        ];
    }
}
