<?php

namespace HiEvents\Http\Actions\Organizers;

use HiEvents\DomainObjects\Status\OrganizerStatus;
use HiEvents\Helper\CustomDomainHelper;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Services\Domain\Customer\CustomerLocaleResolver;
use HiEvents\Services\Domain\Organizer\CustomDomainCurrentEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class GetOrganizerByCustomDomainPublicAction extends BaseAction
{
    public function __construct(
        private readonly OrganizerRepositoryInterface    $organizerRepository,
        private readonly CustomDomainCurrentEventService $currentEventService,
        private readonly CustomerLocaleResolver          $customerLocaleResolver,
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

        $currentEvent = $this->currentEventService->findEventToRedirectTo($organizer);

        return $this->jsonResponse([
            'id' => $organizer->getId(),
            'slug' => $organizer->getSlug(),
            'domain' => $organizer->getCustomDomain(),
            'customer_address_form' => $this->customerLocaleResolver->addressFormForOrganizerId($organizer->getId())->value,
            'current_event' => $currentEvent ? [
                'id' => $currentEvent->getId(),
                'slug' => $currentEvent->getSlug(),
            ] : null,
        ], wrapInData: true);
    }
}
