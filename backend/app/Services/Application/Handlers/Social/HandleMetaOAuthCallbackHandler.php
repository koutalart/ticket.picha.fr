<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Social;

use HiEvents\Exceptions\MetaOAuthException;
use HiEvents\Services\Domain\Social\MetaConnectionService;

class HandleMetaOAuthCallbackHandler
{
    public const ERROR_CANCELLED = 'cancelled';

    public const ERROR_EXPIRED = 'expired';

    public const ERROR_FAILED = 'failed';

    public function __construct(
        private readonly MetaConnectionService $connectionService,
    ) {}

    /**
     * Returns the organizer settings URL the browser is sent back to, never with a token in it.
     */
    public function handle(?string $code, ?string $state, ?string $error): string
    {
        try {
            $payload = $this->connectionService->readState((string) $state);
        } catch (MetaOAuthException) {
            return $this->fallbackUrl();
        }

        $organizerId = (int) $payload['organizer_id'];

        if ($error !== null || $code === null || $code === '') {
            return $this->connectionService->getSettingsUrl($organizerId, ['social_error' => self::ERROR_CANCELLED]);
        }

        try {
            $result = $this->connectionService->handleCallback($code, (string) $state);
        } catch (MetaOAuthException) {
            return $this->connectionService->getSettingsUrl($organizerId, ['social_error' => self::ERROR_FAILED]);
        }

        return $this->connectionService->getSettingsUrl($result->organizerId, ['social_selection' => $result->selectionKey]);
    }

    private function fallbackUrl(): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/manage/organizer?social_error='.self::ERROR_EXPIRED;
    }
}
