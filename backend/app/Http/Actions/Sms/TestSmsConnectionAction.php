<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Sms;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\SmsGatewayException;
use HiEvents\Exceptions\SmsGatewayNotConfiguredException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\ResponseCodes;
use HiEvents\Services\Application\Handlers\Sms\TestSmsConnectionHandler;
use Illuminate\Http\JsonResponse;

class TestSmsConnectionAction extends BaseAction
{
    public function __construct(
        private readonly TestSmsConnectionHandler $handler,
    ) {}

    public function __invoke(int $organizerId): JsonResponse
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        try {
            $result = $this->handler->handle($this->getAuthenticatedAccountId(), $organizerId);
        } catch (SmsGatewayNotConfiguredException $exception) {
            return $this->errorResponse($exception->getMessage(), ResponseCodes::HTTP_UNPROCESSABLE_ENTITY);
        } catch (SmsGatewayException $exception) {
            return $this->errorResponse(
                __('PICHA SMS rejected the connection: :message', ['message' => $exception->getMessage()]),
                ResponseCodes::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return $this->jsonResponse($result->toArray(), wrapInData: true);
    }
}
