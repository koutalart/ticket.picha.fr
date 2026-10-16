<?php

namespace HiEvents\Http\Actions\Events\CoverVideo;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Exceptions\CoverVideoUploadException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Event\UploadEventCoverVideoRequest;
use HiEvents\Resources\Image\ImageResource;
use HiEvents\Services\Application\Handlers\Event\CoverVideo\DTO\UploadEventCoverVideoDTO;
use HiEvents\Services\Application\Handlers\Event\CoverVideo\UploadEventCoverVideoHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class UploadEventCoverVideoAction extends BaseAction
{
    public function __construct(
        private readonly UploadEventCoverVideoHandler $handler,
    ) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(UploadEventCoverVideoRequest $request, int $eventId): JsonResponse
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class);

        try {
            $video = $this->handler->handle(new UploadEventCoverVideoDTO(
                eventId: $eventId,
                accountId: $this->getAuthenticatedAccountId(),
                video: $request->file('video'),
            ));
        } catch (CoverVideoUploadException $exception) {
            throw ValidationException::withMessages(['video' => $exception->getMessage()]);
        }

        return $this->resourceResponse(ImageResource::class, $video);
    }
}
