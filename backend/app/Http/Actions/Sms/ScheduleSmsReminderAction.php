<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Sms;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Exceptions\SmsReminderValidationException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Sms\SmsReminderRequest;
use HiEvents\Http\ResponseCodes;
use HiEvents\Resources\Sms\SmsCampaignResource;
use HiEvents\Services\Application\Handlers\Sms\ScheduleSmsReminderHandler;
use HiEvents\Services\Domain\Sms\DTO\SmsReminderRequestDTO;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Throwable;

class ScheduleSmsReminderAction extends BaseAction
{
    public function __construct(
        private readonly ScheduleSmsReminderHandler $handler,
    ) {}

    /**
     * @throws ValidationException
     * @throws Throwable
     */
    public function __invoke(SmsReminderRequest $request, int $organizerId): JsonResponse|Response
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        try {
            $reminder = $this->handler->handle(new SmsReminderRequestDTO(
                organizerId: $organizerId,
                eventId: (int) $request->validated('event_id'),
                message: $request->validated('message'),
                sendAt: $request->validated('send_at'),
                name: $request->validated('name'),
                userId: $this->getAuthenticatedUser()->getId(),
            ));
        } catch (ResourceNotFoundException) {
            return $this->notFoundResponse();
        } catch (SmsReminderValidationException $exception) {
            throw ValidationException::withMessages([$exception->getField() => $exception->getMessage()]);
        }

        return $this->resourceResponse(SmsCampaignResource::class, $reminder, ResponseCodes::HTTP_CREATED);
    }
}
