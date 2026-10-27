<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Social;

use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\ResponseCodes;
use HiEvents\Services\Application\Handlers\Social\HandleMetaWebhookHandler;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Meta webhooks, signed with the app secret (X-Hub-Signature-256).
 */
class ReceiveMetaWebhookActionPublic extends BaseAction
{
    public function __construct(
        private readonly HandleMetaWebhookHandler $handler,
    ) {}

    public function __invoke(Request $request): Response
    {
        $accepted = $this->handler->receive((string) $request->getContent(), $request->header('X-Hub-Signature-256'));

        return $accepted
            ? $this->textResponse('EVENT_RECEIVED')
            : $this->textResponse('', ResponseCodes::HTTP_FORBIDDEN);
    }
}
