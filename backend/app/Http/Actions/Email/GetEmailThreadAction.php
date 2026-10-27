<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Email;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Resources\Email\EmailThreadDetailResource;
use HiEvents\Services\Application\Handlers\Email\GetEmailThreadHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class GetEmailThreadAction extends BaseAction
{
    public function __construct(
        private readonly GetEmailThreadHandler $handler,
    ) {}

    public function __invoke(int $organizerId, int $threadId): JsonResponse|Response
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        try {
            $detail = $this->handler->handle($organizerId, $threadId);
        } catch (ResourceNotFoundException) {
            return $this->notFoundResponse();
        }

        return $this->resourceResponse(EmailThreadDetailResource::class, $detail);
    }
}
