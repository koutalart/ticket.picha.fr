<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Sms;

use HiEvents\DomainObjects\Enums\LiveChatStyle;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\InvalidSmsPhoneNumberException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Sms\UpdateOrganizerSmsSettingsRequest;
use HiEvents\Resources\Sms\OrganizerSmsSettingsResource;
use HiEvents\Services\Application\Handlers\Sms\DTO\UpdateOrganizerSmsSettingsDTO;
use HiEvents\Services\Application\Handlers\Sms\UpdateOrganizerSmsSettingsHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Optional;

class UpdateOrganizerSmsSettingsAction extends BaseAction
{
    public function __construct(
        private readonly UpdateOrganizerSmsSettingsHandler $handler,
    ) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(UpdateOrganizerSmsSettingsRequest $request, int $organizerId): JsonResponse
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        $validated = $request->validated();

        try {
            $result = $this->handler->handle(new UpdateOrganizerSmsSettingsDTO(
                accountId: $this->getAuthenticatedAccountId(),
                organizerId: $organizerId,
                apiKey: array_key_exists('api_key', $validated) ? $validated['api_key'] : Optional::create(),
                fromNumber: array_key_exists('from_number', $validated) ? $validated['from_number'] : Optional::create(),
                notifyOnInboundSms: array_key_exists('notify_on_inbound_sms', $validated) ? (bool) $validated['notify_on_inbound_sms'] : Optional::create(),
                assistantEnabled: array_key_exists('assistant_enabled', $validated) ? (bool) $validated['assistant_enabled'] : Optional::create(),
                assistantInstructions: array_key_exists('assistant_instructions', $validated) ? $validated['assistant_instructions'] : Optional::create(),
                assistantAutoSend: array_key_exists('assistant_auto_send', $validated) ? (bool) $validated['assistant_auto_send'] : Optional::create(),
                assistantHoldingReplyEnabled: array_key_exists('assistant_holding_reply_enabled', $validated) ? (bool) $validated['assistant_holding_reply_enabled'] : Optional::create(),
                assistantHoldingReply: array_key_exists('assistant_holding_reply', $validated) ? $validated['assistant_holding_reply'] : Optional::create(),
                liveChatEnabled: array_key_exists('live_chat_enabled', $validated) ? (bool) $validated['live_chat_enabled'] : Optional::create(),
                liveChatRelayLabel: array_key_exists('live_chat_relay_label', $validated) ? $validated['live_chat_relay_label'] : Optional::create(),
                liveChatStyle: array_key_exists('live_chat_style', $validated) ? LiveChatStyle::from($validated['live_chat_style']) : Optional::create(),
                defaultRemindersEnabled: array_key_exists('sms_default_reminders_enabled', $validated) ? (bool) $validated['sms_default_reminders_enabled'] : Optional::create(),
                organizerAssistantEnabled: array_key_exists('organizer_assistant_enabled', $validated) ? (bool) $validated['organizer_assistant_enabled'] : Optional::create(),
                organizerAssistantInstructions: array_key_exists('organizer_assistant_instructions', $validated) ? $validated['organizer_assistant_instructions'] : Optional::create(),
                checkinWelcomeSmsEnabled: array_key_exists('checkin_welcome_sms_enabled', $validated) ? (bool) $validated['checkin_welcome_sms_enabled'] : Optional::create(),
            ));
        } catch (InvalidSmsPhoneNumberException $exception) {
            throw ValidationException::withMessages(['from_number' => $exception->getMessage()]);
        }

        return $this->resourceResponse(OrganizerSmsSettingsResource::class, $result);
    }
}
