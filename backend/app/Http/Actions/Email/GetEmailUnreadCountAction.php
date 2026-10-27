<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Email;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\Email\GetEmailThreadsHandler;
use Illuminate\Http\JsonResponse;

class GetEmailUnreadCountAction extends BaseAction
{
    public function __construct(
        private readonly GetEmailThreadsHandler $handler,
    ) {}

    public function __invoke(int $organizerId): JsonResponse
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        return $this->jsonResponse(['unread_threads' => $this->handler->countUnread($organizerId)], wrapInData: true);
    }
}
