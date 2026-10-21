<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Sms;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Resources\Sms\SmsConversationDetailResource;
use HiEvents\Services\Application\Handlers\Sms\GetSmsConversationHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class GetSmsConversationAction extends BaseAction
{
    public function __construct(
        private readonly GetSmsConversationHandler $handler,
    ) {}

    public function __invoke(int $organizerId, int $conversationId): JsonResponse|Response
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        try {
            $detail = $this->handler->handle($organizerId, $conversationId);
        } catch (ResourceNotFoundException) {
            return $this->notFoundResponse();
        }

        return $this->resourceResponse(SmsConversationDetailResource::class, $detail);
    }
}
