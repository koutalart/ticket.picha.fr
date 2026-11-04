<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Sms;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Sms\UpdateSmsLineKeywordRequest;
use HiEvents\Resources\Sms\SmsLineKeywordResource;
use HiEvents\Services\Application\Handlers\Sms\UpdateSmsLineKeywordHandler;
use HiEvents\Services\Domain\Sms\DTO\UpdateSmsLineKeywordDTO;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class UpdateSmsLineKeywordAction extends BaseAction
{
    public function __construct(
        private readonly UpdateSmsLineKeywordHandler $handler,
    ) {}

    public function __invoke(UpdateSmsLineKeywordRequest $request, int $organizerId, int $keywordId): JsonResponse|Response
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        try {
            $keyword = $this->handler->handle(new UpdateSmsLineKeywordDTO(
                organizerId: $organizerId,
                keywordId: $keywordId,
                isActive: (bool) $request->validated('is_active'),
                welcomeMessage: $request->validated('welcome_message'),
            ));
        } catch (ResourceNotFoundException) {
            return $this->notFoundResponse();
        }

        return $this->resourceResponse(SmsLineKeywordResource::class, $keyword);
    }
}
