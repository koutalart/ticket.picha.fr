<?php

namespace HiEvents\Http\Actions\Organizers\Font;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\Organizer\Font\DeleteOrganizerFontHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class DeleteOrganizerFontAction extends BaseAction
{
    public function __construct(
        private readonly DeleteOrganizerFontHandler $handler,
    ) {}

    public function __invoke(int $organizerId, int $fontId): Response|JsonResponse
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        try {
            $this->handler->handle($organizerId, $fontId);
        } catch (ResourceNotFoundException $exception) {
            return $this->errorResponse($exception->getMessage(), Response::HTTP_NOT_FOUND);
        }

        return $this->deletedResponse();
    }
}
