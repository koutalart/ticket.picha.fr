<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\QrLinks;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Exceptions\UniqueIdGenerationFailedException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\QrLink\UpsertQrLinkRequest;
use HiEvents\Http\ResponseCodes;
use HiEvents\Resources\QrLink\QrLinkResource;
use HiEvents\Services\Application\Handlers\QrLink\CreateQrLinkHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class CreateQrLinkAction extends BaseAction
{
    public function __construct(
        private readonly CreateQrLinkHandler $handler,
    ) {}

    /**
     * @throws ValidationException
     * @throws UniqueIdGenerationFailedException
     */
    public function __invoke(UpsertQrLinkRequest $request, int $organizerId): JsonResponse
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        try {
            $qrLink = $this->handler->handle(
                QrLinkDTOFactory::fromRequest($request, $this->getAuthenticatedAccountId(), $organizerId)
            );
        } catch (ResourceNotFoundException $exception) {
            throw ValidationException::withMessages(['event_id' => $exception->getMessage()]);
        }

        return $this->resourceResponse(
            resource: QrLinkResource::class,
            data: $qrLink,
            statusCode: ResponseCodes::HTTP_CREATED,
        );
    }
}
