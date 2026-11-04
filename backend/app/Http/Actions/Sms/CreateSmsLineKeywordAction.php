<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Sms;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\SmsLineKeywordValidationException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Sms\CreateSmsLineKeywordRequest;
use HiEvents\Http\ResponseCodes;
use HiEvents\Resources\Sms\SmsLineKeywordResource;
use HiEvents\Services\Application\Handlers\Sms\CreateSmsLineKeywordHandler;
use HiEvents\Services\Domain\Sms\DTO\CreateSmsLineKeywordDTO;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class CreateSmsLineKeywordAction extends BaseAction
{
    public function __construct(
        private readonly CreateSmsLineKeywordHandler $handler,
    ) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(CreateSmsLineKeywordRequest $request, int $organizerId): JsonResponse
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        try {
            $keyword = $this->handler->handle(new CreateSmsLineKeywordDTO(
                organizerId: $organizerId,
                keyword: $request->validated('keyword'),
                welcomeMessage: $request->validated('welcome_message'),
            ));
        } catch (SmsLineKeywordValidationException $exception) {
            throw ValidationException::withMessages(['keyword' => $exception->getMessage()]);
        }

        return $this->resourceResponse(SmsLineKeywordResource::class, $keyword, ResponseCodes::HTTP_CREATED);
    }
}
