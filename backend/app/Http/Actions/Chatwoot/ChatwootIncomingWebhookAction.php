<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Chatwoot;

use HiEvents\Exceptions\ChatwootWebhookUnauthorizedException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\ResponseCodes;
use HiEvents\Services\Application\Handlers\Chatwoot\HandleChatwootWebhookHandler;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

class ChatwootIncomingWebhookAction extends BaseAction
{
    public function __construct(
        private readonly HandleChatwootWebhookHandler $handler,
    ) {}

    public function __invoke(Request $request, string $webhookToken): Response
    {
        $event = json_decode($request->getContent(), true);

        try {
            $this->handler->handle($webhookToken, is_array($event) ? $event : []);
        } catch (ChatwootWebhookUnauthorizedException) {
            return $this->noContentResponse(ResponseCodes::HTTP_UNAUTHORIZED);
        } catch (Throwable $exception) {
            logger()->error('Failed to handle Chatwoot webhook', ['exception' => $exception]);

            return $this->noContentResponse(ResponseCodes::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->noContentResponse(ResponseCodes::HTTP_OK);
    }
}
