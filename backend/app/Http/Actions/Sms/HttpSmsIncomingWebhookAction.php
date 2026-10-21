<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Sms;

use HiEvents\Exceptions\SmsWebhookUnauthorizedException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\ResponseCodes;
use HiEvents\Services\Application\Handlers\Sms\DTO\HttpSmsWebhookDTO;
use HiEvents\Services\Application\Handlers\Sms\HandleHttpSmsWebhookHandler;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

class HttpSmsIncomingWebhookAction extends BaseAction
{
    public function __construct(
        private readonly HandleHttpSmsWebhookHandler $handler,
    ) {}

    public function __invoke(Request $request, string $webhookToken): Response
    {
        try {
            $this->handler->handle(new HttpSmsWebhookDTO(
                webhookToken: $webhookToken,
                bearerToken: $request->bearerToken(),
                eventType: $request->header('X-Event-Type'),
                payload: $request->getContent(),
            ));
        } catch (SmsWebhookUnauthorizedException) {
            return $this->noContentResponse(ResponseCodes::HTTP_UNAUTHORIZED);
        } catch (Throwable $exception) {
            logger()->error('Failed to handle incoming httpSMS webhook', ['exception' => $exception]);

            return $this->noContentResponse(ResponseCodes::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->noContentResponse(ResponseCodes::HTTP_OK);
    }
}
