<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Sms;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\Sms\GetSmsConversationsHandler;
use Illuminate\Http\JsonResponse;

class GetSmsUnreadCountAction extends BaseAction
{
    public function __construct(
        private readonly GetSmsConversationsHandler $handler,
    ) {}

    public function __invoke(int $organizerId): JsonResponse
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        return $this->jsonResponse(['unread_conversations' => $this->handler->countUnread($organizerId)], wrapInData: true);
    }
}
