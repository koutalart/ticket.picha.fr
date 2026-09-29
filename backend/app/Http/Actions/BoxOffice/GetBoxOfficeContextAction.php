<?php

namespace HiEvents\Http\Actions\BoxOffice;

use HiEvents\DomainObjects\Status\UserStatus;
use HiEvents\Exceptions\UnauthorizedException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Resources\BoxOffice\BoxOfficeContextResource;
use HiEvents\Services\Application\Handlers\BoxOffice\GetBoxOfficeContextHandler;
use Illuminate\Http\JsonResponse;

/**
 * D23 (PICHA_KIOSK_V2_DECISIONS.md) — GET /box-office/context: the event(s)
 * the authenticated user may operate the Kiosk for. Not gated by
 * isActionAuthorized() (an operator would be refused): any ACTIVE account
 * user may ask, the handler scopes the answer to their role.
 */
class GetBoxOfficeContextAction extends BaseAction
{
    public function __construct(
        private readonly GetBoxOfficeContextHandler $handler,
    ) {}

    public function __invoke(): JsonResponse
    {
        $accountUser = $this->getAuthenticatedUser()->getCurrentAccountUser();

        if ($accountUser?->getStatus() !== UserStatus::ACTIVE->name) {
            throw new UnauthorizedException;
        }

        $events = $this->handler->handle(
            userId: $this->getAuthenticatedUser()->getId(),
            role: $accountUser->getRole(),
            accountId: $accountUser->getAccountId(),
        );

        return $this->resourceResponse(BoxOfficeContextResource::class, $events);
    }
}
