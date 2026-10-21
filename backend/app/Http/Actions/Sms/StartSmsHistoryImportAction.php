<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Sms;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Exceptions\SmsGatewayNotConfiguredException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\ResponseCodes;
use HiEvents\Resources\Sms\OrganizerSmsSettingsResource;
use HiEvents\Services\Application\Handlers\Sms\StartSmsHistoryImportHandler;
use Illuminate\Http\JsonResponse;

class StartSmsHistoryImportAction extends BaseAction
{
    public function __construct(
        private readonly StartSmsHistoryImportHandler $handler,
    ) {}

    public function __invoke(int $organizerId): JsonResponse
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        try {
            $settings = $this->handler->handle($this->getAuthenticatedAccountId(), $organizerId);
        } catch (SmsGatewayNotConfiguredException $exception) {
            return $this->errorResponse($exception->getMessage(), ResponseCodes::HTTP_UNPROCESSABLE_ENTITY);
        } catch (ResourceConflictException $exception) {
            return $this->errorResponse($exception->getMessage(), ResponseCodes::HTTP_CONFLICT);
        }

        return $this->resourceResponse(OrganizerSmsSettingsResource::class, $settings, ResponseCodes::HTTP_ACCEPTED);
    }
}
