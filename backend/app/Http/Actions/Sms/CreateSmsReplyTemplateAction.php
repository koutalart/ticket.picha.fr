<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Sms;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\SmsReplyTemplateValidationException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Sms\UpsertSmsReplyTemplateRequest;
use HiEvents\Http\ResponseCodes;
use HiEvents\Resources\Sms\SmsReplyTemplateResource;
use HiEvents\Services\Application\Handlers\Sms\CreateSmsReplyTemplateHandler;
use HiEvents\Services\Domain\Sms\DTO\SmsReplyTemplateDTO;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class CreateSmsReplyTemplateAction extends BaseAction
{
    public function __construct(
        private readonly CreateSmsReplyTemplateHandler $handler,
    ) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(UpsertSmsReplyTemplateRequest $request, int $organizerId): JsonResponse
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        try {
            $template = $this->handler->handle(new SmsReplyTemplateDTO(
                organizerId: $organizerId,
                name: $request->validated('name'),
                content: $request->validated('content'),
            ));
        } catch (SmsReplyTemplateValidationException $exception) {
            throw ValidationException::withMessages(['content' => $exception->getMessage()]);
        }

        return $this->resourceResponse(SmsReplyTemplateResource::class, $template, ResponseCodes::HTTP_CREATED);
    }
}
