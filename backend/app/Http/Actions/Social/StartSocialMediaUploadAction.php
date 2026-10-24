<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Social;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\InvalidSocialMediaFileException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Social\StartSocialMediaUploadRequest;
use HiEvents\Http\ResponseCodes;
use HiEvents\Resources\Social\SocialMediaFileResource;
use HiEvents\Services\Application\Handlers\Social\StartSocialMediaUploadHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class StartSocialMediaUploadAction extends BaseAction
{
    public function __construct(
        private readonly StartSocialMediaUploadHandler $handler,
    ) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(StartSocialMediaUploadRequest $request, int $organizerId): JsonResponse
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        try {
            $file = $this->handler->handle(
                $this->getAuthenticatedAccountId(),
                $organizerId,
                $this->getAuthenticatedUser()->getId(),
                (string) $request->validated('file_name'),
                (string) $request->validated('mime_type'),
                (int) $request->validated('size'),
            );
        } catch (InvalidSocialMediaFileException $exception) {
            throw ValidationException::withMessages(['file' => $exception->getMessage()]);
        }

        return $this->resourceResponse(SocialMediaFileResource::class, $file, ResponseCodes::HTTP_CREATED);
    }
}
