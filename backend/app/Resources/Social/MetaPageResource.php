<?php

declare(strict_types=1);

namespace HiEvents\Resources\Social;

use HiEvents\Resources\BaseResource;
use HiEvents\Services\Infrastructure\Meta\DTO\MetaPageDTO;

/**
 * Never exposes the page access token.
 *
 * @property MetaPageDTO $resource
 */
class MetaPageResource extends BaseResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'instagram_account_id' => $this->resource->instagramAccountId,
            'instagram_username' => $this->resource->instagramUsername,
            'can_publish' => $this->resource->canCreateContent(),
        ];
    }
}
