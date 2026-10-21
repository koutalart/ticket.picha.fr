<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Sms;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Exceptions\SmsAssistantFailedException;
use HiEvents\Exceptions\SmsAssistantLimitReachedException;
use HiEvents\Exceptions\SmsAssistantUnavailableException;
use HiEvents\Exceptions\SmsRecipientOptedOutException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\ResponseCodes;
use HiEvents\Resources\Sms\SmsConversationResource;
use HiEvents\Services\Application\Handlers\Sms\RegenerateSmsDraftHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class RegenerateSmsDraftAction extends BaseAction
{
    public function __construct(
        private readonly RegenerateSmsDraftHandler $handler,
    ) {}

    public function __invoke(int $organizerId, int $conversationId): JsonResponse|Response
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        try {
            $conversation = $this->handler->handle($organizerId, $conversationId);
        } catch (ResourceNotFoundException) {
            return $this->notFoundResponse();
        } catch (SmsAssistantUnavailableException|SmsRecipientOptedOutException $exception) {
            return $this->errorResponse($exception->getMessage(), ResponseCodes::HTTP_UNPROCESSABLE_ENTITY);
        } catch (SmsAssistantLimitReachedException $exception) {
            return $this->errorResponse($exception->getMessage(), ResponseCodes::HTTP_TOO_MANY_REQUESTS);
        } catch (SmsAssistantFailedException $exception) {
            return $this->errorResponse($exception->getMessage(), ResponseCodes::HTTP_BAD_GATEWAY);
        }

        return $this->resourceResponse(SmsConversationResource::class, $conversation);
    }
}
