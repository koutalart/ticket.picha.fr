<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Sms;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Exceptions\SmsReminderNotCancellableException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\ResponseCodes;
use HiEvents\Resources\Sms\SmsCampaignResource;
use HiEvents\Services\Application\Handlers\Sms\CancelSmsReminderHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class CancelSmsReminderAction extends BaseAction
{
    public function __construct(
        private readonly CancelSmsReminderHandler $handler,
    ) {}

    public function __invoke(int $organizerId, int $reminderId): JsonResponse|Response
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        try {
            $reminder = $this->handler->handle($organizerId, $reminderId);
        } catch (ResourceNotFoundException) {
            return $this->notFoundResponse();
        } catch (SmsReminderNotCancellableException $exception) {
            return $this->errorResponse($exception->getMessage(), ResponseCodes::HTTP_CONFLICT);
        }

        return $this->resourceResponse(SmsCampaignResource::class, $reminder);
    }
}
