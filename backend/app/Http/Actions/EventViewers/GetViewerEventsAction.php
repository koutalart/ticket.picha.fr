<?php

namespace HiEvents\Http\Actions\EventViewers;

use HiEvents\DomainObjects\Status\UserStatus;
use HiEvents\Exceptions\UnauthorizedException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\EventViewer\GetViewerEventsHandler;
use Illuminate\Http\JsonResponse;

/**
 * Events a read-only EVENT_VIEWER may consult. Other roles get an empty list:
 * they already reach their events through the normal dashboard.
 */
class GetViewerEventsAction extends BaseAction
{
    public function __construct(
        private readonly GetViewerEventsHandler $handler,
    ) {}

    public function __invoke(): JsonResponse
    {
        $user = $this->getAuthenticatedUser();
        $accountUser = $user->getCurrentAccountUser();

        if ($accountUser?->getStatus() !== UserStatus::ACTIVE->name) {
            throw new UnauthorizedException;
        }

        return $this->jsonResponse([
            'data' => $this->handler->handle(
                userId: $user->getId(),
                role: $accountUser->getRole(),
                accountId: $accountUser->getAccountId(),
            ),
        ]);
    }
}
