<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Sms;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\Status\SmsConversationStatus;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Sms\UpdateSmsConversationRequest;
use HiEvents\Resources\Sms\SmsConversationResource;
use HiEvents\Services\Application\Handlers\Sms\UpdateSmsConversationStatusHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class UpdateSmsConversationAction extends BaseAction
{
    public function __construct(
        private readonly UpdateSmsConversationStatusHandler $handler,
    ) {}

    public function __invoke(UpdateSmsConversationRequest $request, int $organizerId, int $conversationId): JsonResponse|Response
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        try {
            $conversation = $this->handler->handle(
                $organizerId,
                $conversationId,
                SmsConversationStatus::from($request->validated('status')),
            );
        } catch (ResourceNotFoundException) {
            return $this->notFoundResponse();
        }

        return $this->resourceResponse(SmsConversationResource::class, $conversation);
    }
}
