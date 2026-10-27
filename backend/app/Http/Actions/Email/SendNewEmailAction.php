<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Email;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\EmailRecipientNotAllowedException;
use HiEvents\Exceptions\EmailSendFailedException;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Email\NewEmailMessageRequest;
use HiEvents\Http\ResponseCodes;
use HiEvents\Resources\Email\EmailMessageResource;
use HiEvents\Services\Application\Handlers\Email\SendEmailMessageHandler;
use HiEvents\Services\Domain\Email\Inbox\DTO\ComposeEmailDTO;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class SendNewEmailAction extends BaseAction
{
    public function __construct(
        private readonly SendEmailMessageHandler $handler,
    ) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(NewEmailMessageRequest $request, int $organizerId): JsonResponse|Response
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        try {
            $message = $this->handler->handle(new ComposeEmailDTO(
                organizerId: $organizerId,
                userId: $this->getAuthenticatedUser()->getId(),
                body: $request->validated('body'),
                subject: $request->validated('subject'),
                email: $request->validated('email'),
            ));
        } catch (ResourceNotFoundException) {
            return $this->notFoundResponse();
        } catch (EmailRecipientNotAllowedException $exception) {
            throw ValidationException::withMessages(['email' => $exception->getMessage()]);
        } catch (EmailSendFailedException $exception) {
            return $this->errorResponse($exception->getMessage(), ResponseCodes::HTTP_BAD_GATEWAY);
        }

        return $this->resourceResponse(EmailMessageResource::class, $message, ResponseCodes::HTTP_CREATED);
    }
}
