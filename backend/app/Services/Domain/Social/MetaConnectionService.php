<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Social;

use Carbon\Carbon;
use HiEvents\DomainObjects\Generated\OrganizerSocialConnectionDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerSocialConnectionDomainObject;
use HiEvents\DomainObjects\Status\SocialConnectionStatus;
use HiEvents\Exceptions\MetaAppNotConfiguredException;
use HiEvents\Exceptions\MetaGraphApiException;
use HiEvents\Exceptions\MetaOAuthException;
use HiEvents\Exceptions\SocialConnectionNotFoundException;
use HiEvents\Helper\Url;
use HiEvents\Jobs\Social\SyncSocialFeedJob;
use HiEvents\Repository\Interfaces\OrganizerSocialConnectionRepositoryInterface;
use HiEvents\Services\Domain\Social\DTO\MetaOAuthCallbackResultDTO;
use HiEvents\Services\Infrastructure\Encryption\EncryptedPayloadService;
use HiEvents\Services\Infrastructure\Encryption\Exception\DecryptionFailedException;
use HiEvents\Services\Infrastructure\Encryption\Exception\EncryptedPayloadExpiredException;
use HiEvents\Services\Infrastructure\Meta\DTO\MetaPageDTO;
use HiEvents\Services\Infrastructure\Meta\MetaGraphClient;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Throwable;

class MetaConnectionService
{
    public const CALLBACK_PATH = 'public/social/meta/callback';

    private const SELECTION_CACHE_PREFIX = 'social:meta:selection:';

    private const SELECTION_TTL_MINUTES = 20;

    public function __construct(
        private readonly MetaGraphClient $client,
        private readonly OrganizerSocialConnectionRepositoryInterface $connectionRepository,
        private readonly EncryptedPayloadService $payloadService,
        private readonly CacheRepository $cache,
        private readonly Encrypter $encrypter,
        private readonly LoggerInterface $logger,
    ) {}

    public function isAvailable(): bool
    {
        return $this->client->isConfigured();
    }

    /**
     * @throws MetaAppNotConfiguredException
     */
    public function getLoginUrl(int $accountId, int $organizerId, int $userId): string
    {
        $state = $this->payloadService->encryptPayload([
            'account_id' => $accountId,
            'organizer_id' => $organizerId,
            'user_id' => $userId,
        ], Carbon::now()->addMinutes(self::SELECTION_TTL_MINUTES));

        return $this->client->buildLoginUrl($this->getRedirectUri(), $state);
    }

    /**
     * @throws MetaOAuthException
     */
    public function readState(string $state): array
    {
        try {
            $payload = $this->payloadService->decryptPayload($state);
        } catch (DecryptionFailedException|EncryptedPayloadExpiredException) {
            throw new MetaOAuthException(__('The Facebook connection link has expired. Please try again.'));
        }

        if (! isset($payload['account_id'], $payload['organizer_id'], $payload['user_id'])) {
            throw new MetaOAuthException(__('The Facebook connection link has expired. Please try again.'));
        }

        return $payload;
    }

    /**
     * Exchanges the OAuth code for a long-lived user token and keeps the manageable pages for a few minutes,
     * so the organizer can choose which page to connect.
     *
     * @throws MetaOAuthException
     */
    public function handleCallback(string $code, string $state): MetaOAuthCallbackResultDTO
    {
        $payload = $this->readState($state);

        try {
            $shortToken = $this->client->exchangeCodeForToken($code, $this->getRedirectUri());
            $longToken = $this->client->exchangeForLongLivedToken($shortToken->accessToken);
            $userId = $this->client->getUserId($longToken->accessToken);
            $scopes = $this->client->getGrantedScopes($longToken->accessToken);
            $pages = $this->client->getPages($longToken->accessToken);
        } catch (MetaGraphApiException|MetaAppNotConfiguredException $exception) {
            $this->logger->warning('Meta OAuth callback failed', [
                'organizer_id' => $payload['organizer_id'],
                'message' => $exception->getMessage(),
            ]);

            throw new MetaOAuthException(__('Facebook did not accept the connection: :message', ['message' => $exception->getMessage()]));
        }

        $selectionKey = Str::random(40);

        $this->cache->put(
            self::SELECTION_CACHE_PREFIX.$selectionKey,
            $this->encrypter->encrypt([
                'account_id' => $payload['account_id'],
                'organizer_id' => $payload['organizer_id'],
                'user_id' => $payload['user_id'],
                'facebook_user_id' => $userId,
                'user_access_token' => $longToken->accessToken,
                'user_access_token_expires_in' => $longToken->expiresIn,
                'scopes' => $scopes,
                'pages' => array_map(fn (MetaPageDTO $page) => $page->toArray(), $pages),
            ]),
            Carbon::now()->addMinutes(self::SELECTION_TTL_MINUTES),
        );

        return new MetaOAuthCallbackResultDTO(
            organizerId: (int) $payload['organizer_id'],
            selectionKey: $selectionKey,
        );
    }

    /**
     * @return MetaPageDTO[]
     *
     * @throws MetaOAuthException
     */
    public function getPendingPages(int $accountId, int $organizerId, string $selectionKey): array
    {
        return array_map(
            fn (array $page) => MetaPageDTO::from($page),
            $this->getSelection($accountId, $organizerId, $selectionKey)['pages'],
        );
    }

    /**
     * @throws MetaOAuthException
     */
    public function connect(int $accountId, int $organizerId, string $selectionKey, string $pageId): OrganizerSocialConnectionDomainObject
    {
        $selection = $this->getSelection($accountId, $organizerId, $selectionKey);

        $page = collect($selection['pages'])
            ->map(fn (array $page) => MetaPageDTO::from($page))
            ->first(fn (MetaPageDTO $page) => $page->id === $pageId);

        if ($page === null) {
            throw new MetaOAuthException(__('This Facebook page is not available for this connection.'));
        }

        if (! $page->canCreateContent()) {
            throw new MetaOAuthException(__('Your Facebook account cannot publish on this page. Ask a page admin to give you full access.'));
        }

        $attributes = [
            OrganizerSocialConnectionDomainObjectAbstract::ACCOUNT_ID => $accountId,
            OrganizerSocialConnectionDomainObjectAbstract::STATUS => SocialConnectionStatus::ACTIVE->name,
            OrganizerSocialConnectionDomainObjectAbstract::FACEBOOK_USER_ID => $selection['facebook_user_id'],
            OrganizerSocialConnectionDomainObjectAbstract::USER_ACCESS_TOKEN => $selection['user_access_token'],
            OrganizerSocialConnectionDomainObjectAbstract::USER_ACCESS_TOKEN_EXPIRES_AT => $selection['user_access_token_expires_in']
                ? Carbon::now()->addSeconds((int) $selection['user_access_token_expires_in'])
                : null,
            OrganizerSocialConnectionDomainObjectAbstract::FACEBOOK_PAGE_ID => $page->id,
            OrganizerSocialConnectionDomainObjectAbstract::FACEBOOK_PAGE_NAME => $page->name,
            OrganizerSocialConnectionDomainObjectAbstract::PAGE_ACCESS_TOKEN => $page->accessToken,
            OrganizerSocialConnectionDomainObjectAbstract::INSTAGRAM_ACCOUNT_ID => $page->instagramAccountId,
            OrganizerSocialConnectionDomainObjectAbstract::INSTAGRAM_USERNAME => $page->instagramUsername,
            OrganizerSocialConnectionDomainObjectAbstract::GRANTED_SCOPES => $selection['scopes'],
            OrganizerSocialConnectionDomainObjectAbstract::CONNECTED_BY_USER_ID => $selection['user_id'],
            OrganizerSocialConnectionDomainObjectAbstract::LAST_ERROR => null,
            OrganizerSocialConnectionDomainObjectAbstract::LAST_ERROR_AT => null,
        ];

        $existing = $this->connectionRepository->findFirstWhere([
            OrganizerSocialConnectionDomainObjectAbstract::ORGANIZER_ID => $organizerId,
        ]);

        $connection = $existing
            ? $this->connectionRepository->updateFromArray($existing->getId(), $attributes)
            : $this->connectionRepository->create(array_merge($attributes, [
                OrganizerSocialConnectionDomainObjectAbstract::ORGANIZER_ID => $organizerId,
            ]));

        $this->cache->forget(self::SELECTION_CACHE_PREFIX.$selectionKey);

        try {
            $this->client->subscribePageToWebhooks($page->id, $page->accessToken);
        } catch (Throwable $exception) {
            $this->logger->info('Meta page could not be subscribed to webhooks', [
                'organizer_id' => $organizerId,
                'message' => $exception->getMessage(),
            ]);
        }

        SyncSocialFeedJob::dispatch($organizerId);

        return $connection;
    }

    public function findForOrganizer(int $accountId, int $organizerId): ?OrganizerSocialConnectionDomainObject
    {
        return $this->connectionRepository->findFirstWhere([
            OrganizerSocialConnectionDomainObjectAbstract::ACCOUNT_ID => $accountId,
            OrganizerSocialConnectionDomainObjectAbstract::ORGANIZER_ID => $organizerId,
        ]);
    }

    /**
     * @throws SocialConnectionNotFoundException
     */
    public function disconnect(int $accountId, int $organizerId): void
    {
        $connection = $this->findForOrganizer($accountId, $organizerId);

        if ($connection === null) {
            throw new SocialConnectionNotFoundException(__('No Facebook page is connected.'));
        }

        if ($connection->getFacebookUserId() && $connection->getUserAccessToken()) {
            try {
                $this->client->revokePermissions($connection->getFacebookUserId(), $connection->getUserAccessToken());
            } catch (Throwable $exception) {
                $this->logger->info('Meta permissions could not be revoked on disconnect', [
                    'organizer_id' => $organizerId,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        $this->connectionRepository->deleteById($connection->getId());
    }

    public function markNeedsReconnect(int $connectionId, string $error): void
    {
        $this->connectionRepository->updateFromArray($connectionId, [
            OrganizerSocialConnectionDomainObjectAbstract::STATUS => SocialConnectionStatus::NEEDS_RECONNECT->name,
            OrganizerSocialConnectionDomainObjectAbstract::LAST_ERROR => $error,
            OrganizerSocialConnectionDomainObjectAbstract::LAST_ERROR_AT => Carbon::now(),
        ]);
    }

    /**
     * Permissions the connection still lacks (a connection made before comments were added must be renewed).
     *
     * @return string[]
     */
    public function missingScopes(OrganizerSocialConnectionDomainObject $connection): array
    {
        $granted = $connection->getGrantedScopes();

        if (is_string($granted)) {
            $granted = json_decode($granted, true);
        }

        return array_values(array_diff((array) config('services.meta.oauth_scopes', []), is_array($granted) ? $granted : []));
    }

    public function getRedirectUri(): string
    {
        return Url::getApiUrl(self::CALLBACK_PATH);
    }

    public function getSettingsUrl(int $organizerId, array $queryParams = []): string
    {
        $url = rtrim((string) config('app.frontend_url'), '/').'/manage/organizer/'.$organizerId.'/settings';

        return ($queryParams ? $url.'?'.http_build_query($queryParams) : $url).'#social';
    }

    /**
     * @throws MetaOAuthException
     */
    private function getSelection(int $accountId, int $organizerId, string $selectionKey): array
    {
        $encrypted = $this->cache->get(self::SELECTION_CACHE_PREFIX.$selectionKey);

        if (! is_string($encrypted)) {
            throw new MetaOAuthException(__('The Facebook connection has expired. Please connect again.'));
        }

        $selection = $this->encrypter->decrypt($encrypted);

        if ((int) $selection['account_id'] !== $accountId || (int) $selection['organizer_id'] !== $organizerId) {
            throw new MetaOAuthException(__('The Facebook connection has expired. Please connect again.'));
        }

        return $selection;
    }
}
