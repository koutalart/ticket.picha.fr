<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Sms;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Exceptions\SmsReminderValidationException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Sms\PreviewSmsReminderRequest;
use HiEvents\Services\Application\Handlers\Sms\PreviewSmsReminderHandler;
use HiEvents\Services\Domain\Sms\DTO\SmsReminderRequestDTO;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class PreviewSmsReminderAction extends BaseAction
{
    public function __construct(
        private readonly PreviewSmsReminderHandler $handler,
    ) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(PreviewSmsReminderRequest $request, int $organizerId): JsonResponse|Response
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        try {
            $preview = $this->handler->handle(new SmsReminderRequestDTO(
                organizerId: $organizerId,
                eventId: (int) $request->validated('event_id'),
                message: $request->validated('message'),
                sendAt: $request->validated('send_at'),
            ));
        } catch (ResourceNotFoundException) {
            return $this->notFoundResponse();
        } catch (SmsReminderValidationException $exception) {
            throw ValidationException::withMessages([$exception->getField() => $exception->getMessage()]);
        }

        return $this->jsonResponse([
            'recipients_count' => $preview->recipients,
            'sms_count' => $preview->smsCount,
            'sample_message' => $preview->sampleMessage,
            'active_attendees_count' => $preview->activeAttendees,
            'without_valid_phone_count' => $preview->withoutValidPhone,
            'opted_out_count' => $preview->optedOut,
            'shared_phone_count' => $preview->sharedPhone,
            'personal_link_available' => $preview->personalLinkAvailable,
            'event_timezone' => $preview->eventTimezone,
            'scheduled_at' => $preview->scheduledAt,
        ], wrapInData: true);
    }
}
