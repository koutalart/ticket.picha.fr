<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Sms;

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
                assistantHoldingReply: array_key_exists('assistant_holding_reply', $validated) ? $validated['assistant_holding_reply'] : Optional::create(),
            ));
        } catch (InvalidSmsPhoneNumberException $exception) {
            throw ValidationException::withMessages(['from_number' => $exception->getMessage()]);
        }

        return $this->resourceResponse(OrganizerSmsSettingsResource::class, $result);
    }
}
