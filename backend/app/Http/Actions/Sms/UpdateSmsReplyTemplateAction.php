<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Sms;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Exceptions\SmsReplyTemplateValidationException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Sms\UpsertSmsReplyTemplateRequest;
use HiEvents\Resources\Sms\SmsReplyTemplateResource;
use HiEvents\Services\Application\Handlers\Sms\UpdateSmsReplyTemplateHandler;
use HiEvents\Services\Domain\Sms\DTO\SmsReplyTemplateDTO;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class UpdateSmsReplyTemplateAction extends BaseAction
{
    public function __construct(
        private readonly UpdateSmsReplyTemplateHandler $handler,
    ) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(UpsertSmsReplyTemplateRequest $request, int $organizerId, int $templateId): JsonResponse|Response
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        try {
            $template = $this->handler->handle($templateId, new SmsReplyTemplateDTO(
                organizerId: $organizerId,
                name: $request->validated('name'),
                content: $request->validated('content'),
            ));
        } catch (ResourceNotFoundException) {
            return $this->notFoundResponse();
        } catch (SmsReplyTemplateValidationException $exception) {
            throw ValidationException::withMessages(['content' => $exception->getMessage()]);
        }

        return $this->resourceResponse(SmsReplyTemplateResource::class, $template);
    }
}
