<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Social;

use HiEvents\DomainObjects\Enums\SocialPostFormat;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\InvalidSocialMediaFileException;
use HiEvents\Exceptions\SocialMediaFileNotFoundException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Social\CompleteSocialMediaUploadRequest;
use HiEvents\Resources\Social\SocialMediaFileResource;
use HiEvents\Services\Application\Handlers\Social\CompleteSocialMediaUploadHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class CompleteSocialMediaUploadAction extends BaseAction
{
    public function __construct(
        private readonly CompleteSocialMediaUploadHandler $handler,
    ) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(CompleteSocialMediaUploadRequest $request, int $organizerId, int $fileId): JsonResponse|Response
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        $format = $request->validated('format');

        try {
            $file = $this->handler->handle($organizerId, $fileId, $format ? SocialPostFormat::fromName((string) $format) : null);
        } catch (SocialMediaFileNotFoundException) {
            return $this->notFoundResponse();
        } catch (InvalidSocialMediaFileException $exception) {
            throw ValidationException::withMessages(['file' => $exception->getMessage()]);
        }

        return $this->resourceResponse(SocialMediaFileResource::class, $file);
    }
}
