<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Email;

use HiEvents\Exceptions\EmailProviderException;
use HiEvents\Exceptions\EmailWebhookUnauthorizedException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\ResponseCodes;
use HiEvents\Services\Application\Handlers\Email\HandleEmailProviderWebhookHandler;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

class EmailProviderWebhookAction extends BaseAction
{
    public function __construct(
        private readonly HandleEmailProviderWebhookHandler $handler,
    ) {}

    public function __invoke(Request $request): Response
    {
        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $headers[mb_strtolower($name)] = $values[0] ?? null;
        }

        try {
            $this->handler->handle($request->getContent(), $headers);
        } catch (EmailWebhookUnauthorizedException $exception) {
            logger()->warning('Rejected e-mail provider webhook', ['reason' => $exception->getMessage()]);

            return $this->noContentResponse(ResponseCodes::HTTP_UNAUTHORIZED);
        } catch (EmailProviderException $exception) {
            logger()->error('Unable to fetch a received e-mail from the provider', ['exception' => $exception->getMessage()]);

            return $this->noContentResponse(ResponseCodes::HTTP_BAD_GATEWAY);
        } catch (Throwable $exception) {
            logger()->error('Failed to handle e-mail provider webhook', ['exception' => $exception]);

            return $this->noContentResponse(ResponseCodes::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->noContentResponse(ResponseCodes::HTTP_OK);
    }
}
