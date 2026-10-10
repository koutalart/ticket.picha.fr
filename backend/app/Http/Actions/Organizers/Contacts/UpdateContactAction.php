<?php

namespace HiEvents\Http\Actions\Organizers\Contacts;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Helper\PhoneNumberNormalizer;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Contact\UpdateContactRequest;
use HiEvents\Resources\Contact\ContactResource;
use HiEvents\Services\Application\Handlers\Contact\DTO\UpdateContactDTO;
use HiEvents\Services\Application\Handlers\Contact\UpdateContactHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class UpdateContactAction extends BaseAction
{
    public function __construct(private readonly UpdateContactHandler $handler)
    {
    }

    /**
     * @throws ResourceNotFoundException
     * @throws ValidationException
     */
    public function __invoke(UpdateContactRequest $request, int $organizerId, int $contactId): JsonResponse
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        $phone = $request->validated('phone');
        if ($phone !== null && trim($phone) !== '' && PhoneNumberNormalizer::normalize($phone) === null) {
            throw ValidationException::withMessages(['phone' => __('This phone number is not valid')]);
        }

        if ($request->validated('sms_consent') && ($phone === null || trim($phone) === '')) {
            throw ValidationException::withMessages(['sms_consent' => __('A phone number is required for SMS consent')]);
        }

        $contact = $this->handler->handle(new UpdateContactDTO(
            organizerId: $organizerId,
            contactId: $contactId,
            firstName: $request->validated('first_name'),
            lastName: $request->validated('last_name'),
            phone: $phone,
            emailConsent: (bool) $request->validated('email_consent'),
            smsConsent: (bool) $request->validated('sms_consent'),
        ));

        return $this->resourceResponse(resource: ContactResource::class, data: $contact);
    }
}
