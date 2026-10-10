<?php

namespace HiEvents\Http\Actions\Organizers\Contacts;

use HiEvents\DomainObjects\Enums\ContactImportField;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\ContactImportException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Contact\RunContactImportRequest;
use HiEvents\Services\Application\Handlers\Contact\RunContactImportHandler;
use HiEvents\Services\Domain\Contact\DTO\ContactImportOptionsDTO;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class RunContactImportAction extends BaseAction
{
    public function __construct(private readonly RunContactImportHandler $handler) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(RunContactImportRequest $request, int $organizerId): JsonResponse
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        $mapping = array_map(
            static fn (string $field) => ContactImportField::fromName($field),
            $request->validated('mapping'),
        );

        if (! in_array(ContactImportField::EMAIL, $mapping, true) && ! in_array(ContactImportField::PHONE, $mapping, true)) {
            throw ValidationException::withMessages(['mapping' => __('Choose the column that contains the email address or the phone number')]);
        }

        try {
            $result = $this->handler->handle(
                token: $request->validated('import_token'),
                options: new ContactImportOptionsDTO(
                    organizerId: $organizerId,
                    userId: $this->getAuthenticatedUser()->getId(),
                    fileName: $request->validated('file_name'),
                    mapping: $mapping,
                    tagName: $request->validated('tag_name'),
                    emailConsentDeclared: (bool) $request->validated('email_consent'),
                    smsConsentDeclared: (bool) $request->validated('sms_consent'),
                    defaultCountry: $request->validated('default_country') ?? 'FR',
                ),
                dryRun: (bool) $request->validated('dry_run'),
            );
        } catch (ContactImportException $exception) {
            throw ValidationException::withMessages(['import_token' => $exception->getMessage()]);
        }

        return $this->jsonResponse($result->toArray(), wrapInData: true);
    }
}
