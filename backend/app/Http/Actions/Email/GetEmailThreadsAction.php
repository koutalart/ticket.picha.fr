<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Email;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Resources\Email\EmailThreadResource;
use HiEvents\Services\Application\Handlers\Email\GetEmailThreadsHandler;
use HiEvents\Services\Domain\Email\Inbox\DTO\EmailThreadSearchDTO;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GetEmailThreadsAction extends BaseAction
{
    public function __construct(
        private readonly GetEmailThreadsHandler $handler,
    ) {}

    public function __invoke(Request $request, int $organizerId): JsonResponse
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        $filter = (string) $request->query('filter', EmailThreadSearchDTO::FILTER_ALL);

        $threads = $this->handler->handle($organizerId, new EmailThreadSearchDTO(
            query: mb_substr((string) $request->query('query', ''), 0, 100),
            filter: in_array($filter, EmailThreadSearchDTO::FILTERS, true) ? $filter : EmailThreadSearchDTO::FILTER_ALL,
            page: max(1, (int) $request->query('page', 1)),
            perPage: min(100, max(1, (int) $request->query('per_page', 30))),
        ));

        return $this->resourceResponse(
            resource: EmailThreadResource::class,
            data: $threads,
            meta: [
                'unread_threads' => $this->handler->countUnread($organizerId),
                'reply_tracking_enabled' => $this->handler->isReplyTrackingEnabled(),
            ],
        );
    }
}
