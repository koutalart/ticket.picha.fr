<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\QrLinks;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Resources\QrLink\QrLinkResource;
use HiEvents\Services\Application\Handlers\QrLink\GetQrLinksHandler;
use Illuminate\Http\JsonResponse;

class GetQrLinksAction extends BaseAction
{
    public function __construct(
        private readonly GetQrLinksHandler $handler,
    ) {}

    public function __invoke(int $organizerId): JsonResponse
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        return $this->resourceResponse(QrLinkResource::class, $this->handler->handle($organizerId));
    }
}
