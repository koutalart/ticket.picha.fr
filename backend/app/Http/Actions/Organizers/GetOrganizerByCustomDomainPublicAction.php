<?php

namespace HiEvents\Http\Actions\Organizers;

use HiEvents\DomainObjects\Status\OrganizerStatus;
use HiEvents\Helper\CustomDomainHelper;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class GetOrganizerByCustomDomainPublicAction extends BaseAction
{
    public function __construct(
        private readonly OrganizerRepositoryInterface $organizerRepository,
    )
    {
    }

    public function __invoke(string $domain): Response|JsonResponse
    {
        $domain = CustomDomainHelper::normalize($domain);

        $organizer = $domain === null
            ? null
            : $this->organizerRepository->findFirstWhere(['custom_domain' => $domain]);

        if ($organizer === null || $organizer->getStatus() === OrganizerStatus::ARCHIVED->name) {
            return $this->notFoundResponse();
        }

        return $this->jsonResponse([
            'id' => $organizer->getId(),
            'slug' => $organizer->getSlug(),
            'domain' => $organizer->getCustomDomain(),
        ], wrapInData: true);
    }
}
