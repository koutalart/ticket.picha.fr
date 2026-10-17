<?php

namespace HiEvents\Http\Actions\Organizers\CoverVideo;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\CoverVideoUploadException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Organizer\UploadOrganizerCoverVideoRequest;
use HiEvents\Resources\Image\ImageResource;
use HiEvents\Services\Application\Handlers\Organizer\CoverVideo\DTO\UploadOrganizerCoverVideoDTO;
use HiEvents\Services\Application\Handlers\Organizer\CoverVideo\UploadOrganizerCoverVideoHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class UploadOrganizerCoverVideoAction extends BaseAction
{
    public function __construct(
        private readonly UploadOrganizerCoverVideoHandler $handler,
    ) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(UploadOrganizerCoverVideoRequest $request, int $organizerId): JsonResponse
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        try {
            $video = $this->handler->handle(new UploadOrganizerCoverVideoDTO(
                organizerId: $organizerId,
                accountId: $this->getAuthenticatedAccountId(),
                video: $request->file('video'),
            ));
        } catch (CoverVideoUploadException $exception) {
            throw ValidationException::withMessages(['video' => $exception->getMessage()]);
        }

        return $this->resourceResponse(ImageResource::class, $video);
    }
}
