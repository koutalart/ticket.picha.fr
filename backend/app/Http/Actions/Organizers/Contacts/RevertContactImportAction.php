<?php

namespace HiEvents\Http\Actions\Organizers\Contacts;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Domain\Contact\ContactImportService;
use Illuminate\Http\JsonResponse;

class RevertContactImportAction extends BaseAction
{
    public function __construct(private readonly ContactImportService $importService) {}

    public function __invoke(int $organizerId, int $importId): JsonResponse
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        $deleted = $this->importService->revert($organizerId, $importId);

        if ($deleted === null) {
            return $this->errorResponse(__('Import not found'), 404);
        }

        return $this->jsonResponse(['deleted_count' => $deleted], wrapInData: true);
    }
}
