<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Social;

use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\ResponseCodes;
use HiEvents\Services\Application\Handlers\Social\HandleMetaWebhookHandler;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Subscription check of the Meta webhooks (hub.mode, hub.verify_token, hub.challenge; PHP turns the dots into underscores).
 */
class VerifyMetaWebhookActionPublic extends BaseAction
{
    public function __construct(
        private readonly HandleMetaWebhookHandler $handler,
    ) {}

    public function __invoke(Request $request): Response
    {
        $challenge = $this->handler->verifyChallenge(
            $request->query('hub_mode'),
            $request->query('hub_verify_token'),
            $request->query('hub_challenge'),
        );

        return $challenge === null
            ? $this->textResponse('', ResponseCodes::HTTP_FORBIDDEN)
            : $this->textResponse($challenge);
    }
}
