<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Admin\Organizers;

use HiEvents\DomainObjects\Enums\OrganizerHomepageTemplate;
use HiEvents\DomainObjects\Enums\Role;
use HiEvents\Exceptions\OrganizerNotFoundException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Resources\Organizer\AdminOrganizerResource;
use HiEvents\Services\Application\Handlers\Admin\DTO\UpdateOrganizerHomepageTemplateDTO;
use HiEvents\Services\Application\Handlers\Admin\UpdateOrganizerHomepageTemplateHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UpdateOrganizerHomepageTemplateAction extends BaseAction
{
    public function __construct(
        private readonly UpdateOrganizerHomepageTemplateHandler $handler,
    )
    {
    }

    public function __invoke(Request $request, int $accountId, int $organizerId): JsonResponse
    {
        $this->minimumAllowedRole(Role::SUPERADMIN);

        $validated = $request->validate([
            'homepage_template' => ['required', Rule::enum(OrganizerHomepageTemplate::class)],
        ]);

        try {
            $organizer = $this->handler->handle(new UpdateOrganizerHomepageTemplateDTO(
                accountId: $accountId,
                organizerId: $organizerId,
                homepageTemplate: OrganizerHomepageTemplate::from($validated['homepage_template']),
            ));
        } catch (OrganizerNotFoundException) {
            return $this->notFoundResponse();
        }

        return $this->resourceResponse(
            resource: AdminOrganizerResource::class,
            data: $organizer,
        );
    }
}
