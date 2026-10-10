<?php

namespace HiEvents\Http\Actions\Organizers\Contacts;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\Contact\DTO\ContactFilterDTO;
use HiEvents\Services\Application\Handlers\Contact\GetContactsSummaryHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GetContactsSummaryAction extends BaseAction
{
    public function __construct(private readonly GetContactsSummaryHandler $handler) {}

    public function __invoke(Request $request, int $organizerId): JsonResponse
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        $summary = $this->handler->handle($organizerId, ContactFilterDTO::fromQuery($request->query->all()));

        return $this->jsonResponse($summary->toArray(), wrapInData: true);
    }
}
