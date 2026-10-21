<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Sms;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Resources\Sms\SmsConversationResource;
use HiEvents\Services\Application\Handlers\Sms\GetSmsConversationsHandler;
use HiEvents\Services\Domain\Sms\DTO\SmsConversationSearchDTO;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GetSmsConversationsAction extends BaseAction
{
    private const FILTERS = [
        SmsConversationSearchDTO::FILTER_ALL,
        SmsConversationSearchDTO::FILTER_UNREAD,
        SmsConversationSearchDTO::FILTER_NEEDS_HUMAN,
    ];

    public function __construct(
        private readonly GetSmsConversationsHandler $handler,
    ) {}

    public function __invoke(Request $request, int $organizerId): JsonResponse
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        $filter = (string) $request->query('filter', SmsConversationSearchDTO::FILTER_ALL);

        $conversations = $this->handler->handle($organizerId, new SmsConversationSearchDTO(
            query: mb_substr((string) $request->query('query', ''), 0, 100),
            filter: in_array($filter, self::FILTERS, true) ? $filter : SmsConversationSearchDTO::FILTER_ALL,
            page: max(1, (int) $request->query('page', 1)),
            perPage: min(100, max(1, (int) $request->query('per_page', 30))),
        ));

        return $this->resourceResponse(
            resource: SmsConversationResource::class,
            data: $conversations,
            meta: ['unread_conversations' => $this->handler->countUnread($organizerId)],
        );
    }
}
