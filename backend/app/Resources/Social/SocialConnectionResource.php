<?php

declare(strict_types=1);

namespace HiEvents\Resources\Social;

use HiEvents\Resources\BaseResource;
use HiEvents\Services\Application\Handlers\Social\DTO\SocialConnectionStatusDTO;

/**
 * Never exposes the Meta access tokens.
 *
 * @property SocialConnectionStatusDTO $resource
 */
class SocialConnectionResource extends BaseResource
{
    public function toArray($request): array
    {
        $connection = $this->resource->connection;

        return [
            'available' => $this->resource->available,
            'connected' => $connection !== null,
            'status' => $connection?->getStatus(),
            'facebook_page_id' => $connection?->getFacebookPageId(),
            'facebook_page_name' => $connection?->getFacebookPageName(),
            'instagram_account_id' => $connection?->getInstagramAccountId(),
            'instagram_username' => $connection?->getInstagramUsername(),
            'granted_scopes' => $connection ? $this->scopes($connection->getGrantedScopes()) : [],
            'user_access_token_expires_at' => $connection?->getUserAccessTokenExpiresAt(),
            'last_error' => $connection?->getLastError(),
            'last_error_at' => $connection?->getLastErrorAt(),
            'connected_at' => $connection?->getUpdatedAt(),
        ];
    }

    private function scopes(array|string|null $scopes): array
    {
        if (is_string($scopes)) {
            $scopes = json_decode($scopes, true);
        }

        return is_array($scopes) ? array_values($scopes) : [];
    }
}
