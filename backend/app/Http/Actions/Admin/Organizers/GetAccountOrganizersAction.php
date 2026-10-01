<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Admin\Organizers;

use HiEvents\DomainObjects\Enums\Role;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Resources\Organizer\AdminOrganizerResource;
use Illuminate\Http\JsonResponse;

class GetAccountOrganizersAction extends BaseAction
{
    public function __construct(
        private readonly OrganizerRepositoryInterface $organizerRepository,
    )
    {
    }

    public function __invoke(int $accountId): JsonResponse
    {
        $this->minimumAllowedRole(Role::SUPERADMIN);

        return $this->resourceResponse(
            resource: AdminOrganizerResource::class,
            data: $this->organizerRepository->findWhere(['account_id' => $accountId]),
        );
    }
}
