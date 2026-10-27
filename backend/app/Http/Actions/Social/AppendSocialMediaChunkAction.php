<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Social;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\InvalidSocialMediaFileException;
use HiEvents\Exceptions\SocialMediaFileNotFoundException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Social\AppendSocialMediaChunkRequest;
use HiEvents\Resources\Social\SocialMediaFileResource;
use HiEvents\Services\Application\Handlers\Social\AppendSocialMediaChunkHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class AppendSocialMediaChunkAction extends BaseAction
{
    public function __construct(
        private readonly AppendSocialMediaChunkHandler $handler,
    ) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(AppendSocialMediaChunkRequest $request, int $organizerId, int $fileId): JsonResponse|Response
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        try {
            $file = $this->handler->handle($organizerId, $fileId, (int) $request->validated('index'), $request->file('chunk'));
        } catch (SocialMediaFileNotFoundException) {
            return $this->notFoundResponse();
        } catch (InvalidSocialMediaFileException $exception) {
            throw ValidationException::withMessages(['file' => $exception->getMessage()]);
        }

        return $this->resourceResponse(SocialMediaFileResource::class, $file);
    }
}
