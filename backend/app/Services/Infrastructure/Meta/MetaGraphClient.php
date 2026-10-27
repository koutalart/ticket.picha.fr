<?php

declare(strict_types=1);

namespace HiEvents\Services\Infrastructure\Meta;

use HiEvents\Exceptions\MetaAppNotConfiguredException;
use HiEvents\Exceptions\MetaGraphApiException;
use HiEvents\Services\Infrastructure\Meta\DTO\MetaAccessTokenDTO;
use HiEvents\Services\Infrastructure\Meta\DTO\MetaPageDTO;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class MetaGraphClient
{
    private const GRAPH_URL = 'https://graph.facebook.com';

    private const DIALOG_URL = 'https://www.facebook.com';

    private const VIDEO_UPLOAD_URL = 'https://rupload.facebook.com/video-upload';

    public function isConfigured(): bool
    {
        return ! empty(config('services.meta.app_id')) && ! empty(config('services.meta.app_secret'));
    }

    /**
     * @throws MetaAppNotConfiguredException
     */
    public function buildLoginUrl(string $redirectUri, string $state): string
    {
        $this->assertConfigured();

        $params = [
            'client_id' => config('services.meta.app_id'),
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'response_type' => 'code',
        ];

        if (config('services.meta.login_config_id')) {
            $params['config_id'] = config('services.meta.login_config_id');
            $params['override_default_response_type'] = 'true';
        } else {
            $params['scope'] = implode(',', config('services.meta.oauth_scopes', []));
        }

        return sprintf('%s/%s/dialog/oauth?%s', self::DIALOG_URL, $this->version(), http_build_query($params));
    }

    /**
     * @throws MetaGraphApiException
     * @throws MetaAppNotConfiguredException
     */
    public function exchangeCodeForToken(string $code, string $redirectUri): MetaAccessTokenDTO
    {
        $this->assertConfigured();

        $response = $this->send(fn (PendingRequest $request) => $request->get($this->url('oauth/access_token'), [
            'client_id' => config('services.meta.app_id'),
            'client_secret' => config('services.meta.app_secret'),
            'redirect_uri' => $redirectUri,
            'code' => $code,
        ]));

        return $this->toAccessToken($response);
    }

    /**
     * @throws MetaGraphApiException
     * @throws MetaAppNotConfiguredException
     */
    public function exchangeForLongLivedToken(string $accessToken): MetaAccessTokenDTO
    {
        $this->assertConfigured();

        $response = $this->send(fn (PendingRequest $request) => $request->get($this->url('oauth/access_token'), [
            'grant_type' => 'fb_exchange_token',
            'client_id' => config('services.meta.app_id'),
            'client_secret' => config('services.meta.app_secret'),
            'fb_exchange_token' => $accessToken,
        ]));

        return $this->toAccessToken($response);
    }

    /**
     * @throws MetaGraphApiException
     */
    public function getUserId(string $userAccessToken): string
    {
        return (string) $this->get('me', $userAccessToken, ['fields' => 'id'])->json('id');
    }

    /**
     * @return string[]
     *
     * @throws MetaGraphApiException
     */
    public function getGrantedScopes(string $userAccessToken): array
    {
        return collect($this->get('me/permissions', $userAccessToken)->json('data', []))
            ->filter(fn (array $permission) => ($permission['status'] ?? null) === 'granted')
            ->pluck('permission')
            ->values()
            ->all();
    }

    /**
     * @return MetaPageDTO[]
     *
     * @throws MetaGraphApiException
     */
    public function getPages(string $userAccessToken): array
    {
        $response = $this->get('me/accounts', $userAccessToken, [
            'fields' => 'id,name,access_token,tasks,instagram_business_account{id,username}',
            'limit' => 100,
        ]);

        return collect($response->json('data', []))
            ->map(fn (array $page) => new MetaPageDTO(
                id: (string) $page['id'],
                name: (string) ($page['name'] ?? ''),
                accessToken: (string) ($page['access_token'] ?? ''),
                instagramAccountId: isset($page['instagram_business_account']['id']) ? (string) $page['instagram_business_account']['id'] : null,
                instagramUsername: $page['instagram_business_account']['username'] ?? null,
                tasks: $page['tasks'] ?? [],
            ))
            ->filter(fn (MetaPageDTO $page) => $page->accessToken !== '')
            ->values()
            ->all();
    }

    /**
     * @throws MetaGraphApiException
     */
    public function revokePermissions(string $userId, string $userAccessToken): void
    {
        $this->send(fn (PendingRequest $request) => $request->delete(
            $this->url($userId.'/permissions'),
            ['access_token' => $userAccessToken],
        ));
    }

    /**
     * @throws MetaGraphApiException
     */
    public function createInstagramContainer(string $instagramAccountId, string $accessToken, array $params): string
    {
        return (string) $this->post($instagramAccountId.'/media', $accessToken, $params)->json('id');
    }

    /**
     * @throws MetaGraphApiException
     */
    public function getInstagramContainerStatus(string $containerId, string $accessToken): string
    {
        return (string) $this->get($containerId, $accessToken, ['fields' => 'status_code'])->json('status_code');
    }

    /**
     * @throws MetaGraphApiException
     */
    public function publishInstagramContainer(string $instagramAccountId, string $containerId, string $accessToken): string
    {
        return (string) $this->post($instagramAccountId.'/media_publish', $accessToken, [
            'creation_id' => $containerId,
        ])->json('id');
    }

    /**
     * @return array{usage: int, total: int}|null
     *
     * @throws MetaGraphApiException
     */
    public function getInstagramPublishingQuota(string $instagramAccountId, string $accessToken): ?array
    {
        $data = $this->get($instagramAccountId.'/content_publishing_limit', $accessToken, [
            'fields' => 'quota_usage,config',
        ])->json('data.0');

        if (! is_array($data) || ! isset($data['config']['quota_total'])) {
            return null;
        }

        return [
            'usage' => (int) ($data['quota_usage'] ?? 0),
            'total' => (int) $data['config']['quota_total'],
        ];
    }

    /**
     * @throws MetaGraphApiException
     */
    public function getPermalink(string $objectId, string $accessToken, string $field = 'permalink'): ?string
    {
        return $this->get($objectId, $accessToken, ['fields' => $field])->json($field);
    }

    /**
     * @return array{id: string, post_id: ?string}
     *
     * @throws MetaGraphApiException
     */
    public function publishPagePhoto(string $pageId, string $accessToken, string $imageUrl, ?string $caption, bool $published = true): array
    {
        $params = ['url' => $imageUrl, 'published' => $published ? 'true' : 'false'];

        if ($caption !== null && $caption !== '') {
            $params['message'] = $caption;
        }

        $response = $this->post($pageId.'/photos', $accessToken, $params);

        return ['id' => (string) $response->json('id'), 'post_id' => $response->json('post_id')];
    }

    /**
     * @param  string[]  $attachedMediaIds
     *
     * @throws MetaGraphApiException
     */
    public function publishPageFeedPost(string $pageId, string $accessToken, ?string $message, ?string $link = null, array $attachedMediaIds = []): string
    {
        $params = array_filter([
            'message' => $message,
            'link' => $link,
        ], fn ($value) => $value !== null && $value !== '');

        foreach (array_values($attachedMediaIds) as $index => $mediaId) {
            $params["attached_media[$index]"] = json_encode(['media_fbid' => $mediaId]);
        }

        return (string) $this->post($pageId.'/feed', $accessToken, $params)->json('id');
    }

    /**
     * @throws MetaGraphApiException
     */
    public function publishPagePhotoStory(string $pageId, string $accessToken, string $photoId): string
    {
        return (string) $this->post($pageId.'/photo_stories', $accessToken, ['photo_id' => $photoId])->json('post_id');
    }

    /**
     * Publishes a hosted video as a Page Reel ("video_reels") or Page story ("video_stories").
     *
     * @throws MetaGraphApiException
     */
    public function publishPageVideo(string $pageId, string $accessToken, string $edge, string $videoUrl, ?string $description = null): string
    {
        $videoId = (string) $this->post($pageId.'/'.$edge, $accessToken, ['upload_phase' => 'start'])->json('video_id');

        $this->send(fn (PendingRequest $request) => $request
            ->withHeaders(['Authorization' => 'OAuth '.$accessToken, 'file_url' => $videoUrl])
            ->post(sprintf('%s/%s/%s', self::VIDEO_UPLOAD_URL, $this->version(), $videoId)));

        $params = ['upload_phase' => 'finish', 'video_id' => $videoId];

        if ($edge === 'video_reels') {
            $params['video_state'] = 'PUBLISHED';
        }

        if ($description !== null && $description !== '') {
            $params['description'] = $description;
        }

        $response = $this->post($pageId.'/'.$edge, $accessToken, $params);

        return (string) ($response->json('post_id') ?? $videoId);
    }

    /**
     * @throws MetaGraphApiException
     */
    public function subscribePageToWebhooks(string $pageId, string $pageAccessToken): void
    {
        $this->post($pageId.'/subscribed_apps', $pageAccessToken, ['subscribed_fields' => 'feed']);
    }

    /**
     * Latest media of an Instagram professional account.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws MetaGraphApiException
     */
    public function getInstagramMedia(string $instagramAccountId, string $accessToken, int $limit = 25): array
    {
        return $this->get($instagramAccountId.'/media', $accessToken, [
            'fields' => 'id,caption,media_type,media_product_type,media_url,thumbnail_url,permalink,timestamp,like_count,comments_count,'
                .'children{id,media_type,media_url,thumbnail_url}',
            'limit' => $limit,
        ])->json('data', []);
    }

    /**
     * Stories of an Instagram professional account still online (Instagram keeps them 24 hours).
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws MetaGraphApiException
     */
    public function getInstagramStories(string $instagramAccountId, string $accessToken): array
    {
        return $this->get($instagramAccountId.'/stories', $accessToken, [
            'fields' => 'id,caption,media_type,media_product_type,media_url,thumbnail_url,permalink,timestamp',
        ])->json('data', []);
    }

    /**
     * Copies a media file served by the Meta CDN to a local path.
     *
     * @throws MetaGraphApiException
     */
    public function downloadMedia(string $url, string $localPath): void
    {
        try {
            $response = Http::timeout(config('services.meta.publishing_timeout_seconds', 60))->sink($localPath)->get($url);
        } catch (ConnectionException $exception) {
            throw new MetaGraphApiException($exception->getMessage(), retryable: true, previous: $exception);
        }

        if ($response->failed()) {
            throw new MetaGraphApiException('Media download failed', retryable: $response->serverError(), code: $response->status());
        }
    }

    /**
     * Top-level comments of an Instagram media, with their replies.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws MetaGraphApiException
     */
    public function getInstagramComments(string $mediaId, string $accessToken, int $limit = 50): array
    {
        return $this->get($mediaId.'/comments', $accessToken, [
            'fields' => 'id,text,username,timestamp,like_count,from{id,username},replies{id,text,username,timestamp,like_count,from{id,username}}',
            'limit' => $limit,
        ])->json('data', []);
    }

    /**
     * Latest posts published by a Page.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws MetaGraphApiException
     */
    public function getPagePosts(string $pageId, string $accessToken, int $limit = 25): array
    {
        return $this->get($pageId.'/published_posts', $accessToken, [
            'fields' => 'id,message,created_time,permalink_url,full_picture,'
                .'attachments{media_type,type,url,media{image{src},source},subattachments{media_type,type,media{image{src},source}}},'
                .'reactions.summary(total_count).limit(0),comments.summary(total_count).limit(0)',
            'limit' => $limit,
        ])->json('data', []);
    }

    /**
     * Top-level comments of a Page post, with their replies.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws MetaGraphApiException
     */
    public function getPostComments(string $postId, string $accessToken, int $limit = 50): array
    {
        return $this->get($postId.'/comments', $accessToken, [
            'fields' => 'id,message,created_time,like_count,from{id,name},comments{id,message,created_time,like_count,from{id,name}}',
            'filter' => 'toplevel',
            'order' => 'chronological',
            'limit' => $limit,
        ])->json('data', []);
    }

    /**
     * @throws MetaGraphApiException
     */
    public function replyToInstagramComment(string $commentId, string $accessToken, string $message): string
    {
        return (string) $this->post($commentId.'/replies', $accessToken, ['message' => $message])->json('id');
    }

    /**
     * @throws MetaGraphApiException
     */
    public function replyToFacebookComment(string $commentId, string $accessToken, string $message): string
    {
        return (string) $this->post($commentId.'/comments', $accessToken, ['message' => $message])->json('id');
    }

    /**
     * @throws MetaGraphApiException
     */
    private function get(string $path, string $accessToken, array $query = []): Response
    {
        return $this->send(fn (PendingRequest $request) => $request->get(
            $this->url($path),
            array_merge($query, ['access_token' => $accessToken]),
        ));
    }

    /**
     * @throws MetaGraphApiException
     */
    private function post(string $path, string $accessToken, array $params): Response
    {
        return $this->send(fn (PendingRequest $request) => $request
            ->asForm()
            ->post($this->url($path), array_merge($params, ['access_token' => $accessToken])));
    }

    /**
     * @throws MetaGraphApiException
     */
    private function send(callable $callback): Response
    {
        try {
            /** @var Response $response */
            $response = $callback(Http::acceptJson()->timeout(config('services.meta.publishing_timeout_seconds', 60)));
        } catch (ConnectionException $exception) {
            throw new MetaGraphApiException($exception->getMessage(), retryable: true, previous: $exception);
        }

        if ($response->failed() || $response->json('error') !== null) {
            throw new MetaGraphApiException(
                message: (string) ($response->json('error.error_user_msg') ?? $response->json('error.message') ?? $response->body()),
                retryable: $response->serverError() || $response->status() === 429 || (bool) $response->json('error.is_transient', false),
                code: $response->status(),
                graphErrorCode: $response->json('error.code') !== null ? (int) $response->json('error.code') : null,
                graphErrorSubcode: $response->json('error.error_subcode') !== null ? (int) $response->json('error.error_subcode') : null,
            );
        }

        return $response;
    }

    private function toAccessToken(Response $response): MetaAccessTokenDTO
    {
        return new MetaAccessTokenDTO(
            accessToken: (string) $response->json('access_token'),
            expiresIn: $response->json('expires_in') !== null ? (int) $response->json('expires_in') : null,
        );
    }

    /**
     * @throws MetaAppNotConfiguredException
     */
    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new MetaAppNotConfiguredException(__('The Meta app is not configured on this server.'));
        }
    }

    private function url(string $path): string
    {
        return sprintf('%s/%s/%s', self::GRAPH_URL, $this->version(), $path);
    }

    private function version(): string
    {
        return (string) config('services.meta.graph_api_version');
    }
}
