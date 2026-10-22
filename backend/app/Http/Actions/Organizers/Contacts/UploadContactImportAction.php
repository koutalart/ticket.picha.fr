<?php

namespace HiEvents\Http\Actions\Organizers\Contacts;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\ContactImportException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Contact\UploadContactImportRequest;
use HiEvents\Services\Application\Handlers\Contact\PreviewContactImportHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class UploadContactImportAction extends BaseAction
{
    public function __construct(private readonly PreviewContactImportHandler $handler) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(UploadContactImportRequest $request, int $organizerId): JsonResponse
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        try {
            $preview = $this->handler->handle($organizerId, $request->file('file'));
        } catch (ContactImportException $exception) {
            throw ValidationException::withMessages(['file' => $exception->getMessage()]);
        }

        return $this->jsonResponse($preview, wrapInData: true);
    }
}
