<?php

namespace HiEvents\Http\Actions\Organizers\Contacts;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Repository\Interfaces\ContactTagRepositoryInterface;
use Illuminate\Http\JsonResponse;

class GetContactTagsAction extends BaseAction
{
    public function __construct(private readonly ContactTagRepositoryInterface $contactTagRepository)
    {
    }

    public function __invoke(int $organizerId): JsonResponse
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        return $this->jsonResponse(
            $this->contactTagRepository->getWithCountsForOrganizer($organizerId)->all(),
            wrapInData: true,
        );
    }
}
