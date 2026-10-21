<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Sms;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\InvalidSmsPhoneNumberException;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Exceptions\SmsGatewayException;
use HiEvents\Exceptions\SmsGatewayNotConfiguredException;
use HiEvents\Exceptions\SmsRecipientOptedOutException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Sms\SendSmsReplyRequest;
use HiEvents\Http\ResponseCodes;
use HiEvents\Resources\Sms\SmsMessageResource;
use HiEvents\Services\Application\Handlers\Sms\SendSmsReplyHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class SendSmsReplyAction extends BaseAction
{
    public function __construct(
        private readonly SendSmsReplyHandler $handler,
    ) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(SendSmsReplyRequest $request, int $organizerId, int $conversationId): JsonResponse|Response
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        try {
            $message = $this->handler->handle(
                organizerId: $organizerId,
                conversationId: $conversationId,
                content: $request->validated('content'),
                userId: $this->getAuthenticatedUser()->getId(),
            );
        } catch (ResourceNotFoundException) {
            return $this->notFoundResponse();
        } catch (SmsRecipientOptedOutException|SmsGatewayNotConfiguredException|InvalidSmsPhoneNumberException $exception) {
            throw ValidationException::withMessages(['content' => $exception->getMessage()]);
        } catch (SmsGatewayException $exception) {
            return $this->errorResponse(
                __('PICHA SMS could not send the message: :message', ['message' => $exception->getMessage()]),
                ResponseCodes::HTTP_BAD_GATEWAY,
            );
        }

        return $this->resourceResponse(SmsMessageResource::class, $message, ResponseCodes::HTTP_CREATED);
    }
}
