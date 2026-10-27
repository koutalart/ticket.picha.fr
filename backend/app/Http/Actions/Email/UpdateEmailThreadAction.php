<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Email;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\Status\EmailThreadStatus;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Email\UpdateEmailThreadRequest;
use HiEvents\Resources\Email\EmailThreadResource;
use HiEvents\Services\Application\Handlers\Email\UpdateEmailThreadStatusHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class UpdateEmailThreadAction extends BaseAction
{
    public function __construct(
        private readonly UpdateEmailThreadStatusHandler $handler,
    ) {}

    public function __invoke(UpdateEmailThreadRequest $request, int $organizerId, int $threadId): JsonResponse|Response
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        try {
            $thread = $this->handler->handle(
                $organizerId,
                $threadId,
                EmailThreadStatus::from($request->validated('status')),
            );
        } catch (ResourceNotFoundException) {
            return $this->notFoundResponse();
        }

        return $this->resourceResponse(EmailThreadResource::class, $thread);
    }
}
