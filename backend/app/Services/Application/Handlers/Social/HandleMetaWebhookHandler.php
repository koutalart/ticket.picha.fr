<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Social;

use HiEvents\Jobs\Social\ProcessMetaWebhookJob;
use HiEvents\Services\Domain\Social\Feed\MetaWebhookService;

class HandleMetaWebhookHandler
{
    public function __construct(
        private readonly MetaWebhookService $webhookService,
    ) {}

    public function verifyChallenge(?string $mode, ?string $verifyToken, ?string $challenge): ?string
    {
        return $this->webhookService->verifyChallenge($mode, $verifyToken, $challenge);
    }

    /**
     * Returns false when the signature does not match the app secret; the payload is then ignored.
     */
    public function receive(string $rawBody, ?string $signature): bool
    {
        if (! $this->webhookService->hasValidSignature($rawBody, $signature)) {
            return false;
        }

        $payload = json_decode($rawBody, true);

        if (is_array($payload)) {
            ProcessMetaWebhookJob::dispatch($payload);
        }

        return true;
    }
}
