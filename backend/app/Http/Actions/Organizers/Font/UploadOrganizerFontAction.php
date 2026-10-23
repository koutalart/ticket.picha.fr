<?php

namespace HiEvents\Http\Actions\Organizers\Font;

use HiEvents\DomainObjects\Enums\OrganizerFontWeight;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\InvalidOrganizerFontException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Organizer\Font\UploadOrganizerFontRequest;
use HiEvents\Resources\Organizer\OrganizerFontResource;
use HiEvents\Services\Application\Handlers\Organizer\Font\DTO\UploadOrganizerFontDTO;
use HiEvents\Services\Application\Handlers\Organizer\Font\UploadOrganizerFontHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class UploadOrganizerFontAction extends BaseAction
{
    public function __construct(
        private readonly UploadOrganizerFontHandler $handler,
    ) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(UploadOrganizerFontRequest $request, int $organizerId): JsonResponse
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        try {
            $font = $this->handler->handle(new UploadOrganizerFontDTO(
                organizerId: $organizerId,
                accountId: $this->getAuthenticatedAccountId(),
                familyName: $request->validated('family_name'),
                weight: OrganizerFontWeight::from((int) $request->validated('weight')),
                file: $request->file('font_file'),
            ));
        } catch (InvalidOrganizerFontException $exception) {
            throw ValidationException::withMessages(['font_file' => $exception->getMessage()]);
        }

        return $this->resourceResponse(OrganizerFontResource::class, $font, 201);
    }
}
