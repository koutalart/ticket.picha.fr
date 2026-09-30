<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Admin\Organizers;

use HiEvents\DomainObjects\Enums\Role;
use HiEvents\Exceptions\InvalidCustomDomainException;
use HiEvents\Exceptions\OrganizerNotFoundException;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Resources\Organizer\AdminOrganizerResource;
use HiEvents\Services\Application\Handlers\Admin\DTO\UpdateOrganizerCustomDomainDTO;
use HiEvents\Services\Application\Handlers\Admin\UpdateOrganizerCustomDomainHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class UpdateOrganizerCustomDomainAction extends BaseAction
{
    public function __construct(
        private readonly UpdateOrganizerCustomDomainHandler $handler,
    )
    {
    }

    /**
     * @throws ValidationException
     */
    public function __invoke(Request $request, int $accountId, int $organizerId): JsonResponse
    {
        $this->minimumAllowedRole(Role::SUPERADMIN);

        $validated = $request->validate([
            'custom_domain' => 'nullable|string|max:253',
        ]);

        try {
            $organizer = $this->handler->handle(new UpdateOrganizerCustomDomainDTO(
                accountId: $accountId,
                organizerId: $organizerId,
                customDomain: $validated['custom_domain'] ?? null,
            ));
        } catch (OrganizerNotFoundException) {
            return $this->notFoundResponse();
        } catch (InvalidCustomDomainException|ResourceConflictException $exception) {
            throw ValidationException::withMessages([
                'custom_domain' => $exception->getMessage(),
            ]);
        }

        return $this->resourceResponse(
            resource: AdminOrganizerResource::class,
            data: $organizer,
        );
    }
}
