<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\QrLinks;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\QrLinkReferenceException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\QrLink\UpsertQrLinkRequest;
use HiEvents\Resources\QrLink\QrLinkResource;
use HiEvents\Services\Application\Handlers\QrLink\UpdateQrLinkHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class UpdateQrLinkAction extends BaseAction
{
    public function __construct(
        private readonly UpdateQrLinkHandler $handler,
    ) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(UpsertQrLinkRequest $request, int $organizerId, int $qrLinkId): JsonResponse
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        try {
            $qrLink = $this->handler->handle(
                $qrLinkId,
                QrLinkDTOFactory::fromRequest($request, $this->getAuthenticatedAccountId(), $organizerId),
            );
        } catch (QrLinkReferenceException $exception) {
            throw ValidationException::withMessages([$exception->getField() => $exception->getMessage()]);
        }

        return $this->resourceResponse(QrLinkResource::class, $qrLink);
    }
}
