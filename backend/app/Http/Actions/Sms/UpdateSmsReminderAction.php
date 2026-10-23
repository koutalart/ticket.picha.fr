<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Sms;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Exceptions\SmsReminderNotEditableException;
use HiEvents\Exceptions\SmsReminderValidationException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Sms\UpdateSmsReminderRequest;
use HiEvents\Http\ResponseCodes;
use HiEvents\Resources\Sms\SmsCampaignResource;
use HiEvents\Services\Application\Handlers\Sms\UpdateSmsReminderHandler;
use HiEvents\Services\Domain\Sms\DTO\SmsReminderRequestDTO;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Throwable;

class UpdateSmsReminderAction extends BaseAction
{
    public function __construct(
        private readonly UpdateSmsReminderHandler $handler,
    ) {}

    /**
     * @throws ValidationException
     * @throws Throwable
     */
    public function __invoke(UpdateSmsReminderRequest $request, int $organizerId, int $reminderId): JsonResponse|Response
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        try {
            $reminder = $this->handler->handle($reminderId, new SmsReminderRequestDTO(
                organizerId: $organizerId,
                eventId: 0,
                message: $request->validated('message'),
                sendAt: $request->validated('send_at'),
                name: $request->validated('name'),
                userId: $this->getAuthenticatedUser()->getId(),
            ));
        } catch (ResourceNotFoundException) {
            return $this->notFoundResponse();
        } catch (SmsReminderValidationException $exception) {
            throw ValidationException::withMessages([$exception->getField() => $exception->getMessage()]);
        } catch (SmsReminderNotEditableException $exception) {
            return $this->errorResponse($exception->getMessage(), ResponseCodes::HTTP_CONFLICT);
        }

        return $this->resourceResponse(SmsCampaignResource::class, $reminder);
    }
}
