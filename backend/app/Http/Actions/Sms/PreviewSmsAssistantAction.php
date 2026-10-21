<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Sms;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\SmsAssistantFailedException;
use HiEvents\Exceptions\SmsAssistantLimitReachedException;
use HiEvents\Exceptions\SmsAssistantUnavailableException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Sms\PreviewSmsAssistantRequest;
use HiEvents\Http\ResponseCodes;
use HiEvents\Services\Application\Handlers\Sms\DTO\PreviewSmsAssistantDTO;
use HiEvents\Services\Application\Handlers\Sms\PreviewSmsAssistantHandler;
use Illuminate\Http\JsonResponse;

class PreviewSmsAssistantAction extends BaseAction
{
    public function __construct(
        private readonly PreviewSmsAssistantHandler $handler,
    ) {}

    public function __invoke(PreviewSmsAssistantRequest $request, int $organizerId): JsonResponse
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        try {
            $result = $this->handler->handle(new PreviewSmsAssistantDTO(
                organizerId: $organizerId,
                customerMessage: $request->validated('message'),
                instructions: $request->validated('instructions'),
            ));
        } catch (SmsAssistantUnavailableException $exception) {
            return $this->errorResponse($exception->getMessage(), ResponseCodes::HTTP_UNPROCESSABLE_ENTITY);
        } catch (SmsAssistantLimitReachedException $exception) {
            return $this->errorResponse($exception->getMessage(), ResponseCodes::HTTP_TOO_MANY_REQUESTS);
        } catch (SmsAssistantFailedException $exception) {
            return $this->errorResponse($exception->getMessage(), ResponseCodes::HTTP_BAD_GATEWAY);
        }

        return $this->jsonResponse([
            'outcome' => $result->outcome->name,
            'reply' => $result->reply,
            'reason' => $result->reason,
        ], wrapInData: true);
    }
}
