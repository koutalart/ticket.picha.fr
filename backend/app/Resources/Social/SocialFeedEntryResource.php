<?php

declare(strict_types=1);

namespace HiEvents\Resources\Social;

use HiEvents\Resources\BaseResource;
use HiEvents\Services\Domain\Social\Feed\DTO\SocialFeedEntryDTO;

/**
 * @property SocialFeedEntryDTO $resource
 */
class SocialFeedEntryResource extends BaseResource
{
    public function toArray($request): array
    {
        $post = $this->resource->post;
        $item = $this->resource->item;

        return [
            'key' => $item ? 'network-'.$item->getId() : 'picha-'.$post?->getId(),
            'date' => $this->resource->date->toIso8601String(),
            'source' => $item ? ($post ? 'PICHA' : 'NETWORK') : 'PICHA',
            'post' => $post ? (new SocialPostResource($post))->toArray($request) : null,
            'item' => $item ? (new SocialFeedItemResource($item))->toArray($request) : null,
        ];
    }
}
