<?php

namespace HiEvents\Http\Actions\Organizers\Contacts;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Helper\PhoneNumberNormalizer;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Contact\CreateContactRequest;
use HiEvents\Http\ResponseCodes;
use HiEvents\Resources\Contact\ContactResource;
use HiEvents\Services\Application\Handlers\Contact\CreateContactHandler;
use HiEvents\Services\Application\Handlers\Contact\DTO\UpdateContactDTO;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class CreateContactAction extends BaseAction
{
    public function __construct(private readonly CreateContactHandler $handler) {}

    /**
     * @throws ResourceNotFoundException
     * @throws ValidationException
     */
    public function __invoke(CreateContactRequest $request, int $organizerId): JsonResponse
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        $phone = $request->validated('phone');
        if ($phone !== null && trim($phone) !== '' && PhoneNumberNormalizer::normalize($phone) === null) {
            throw ValidationException::withMessages(['phone' => __('This phone number is not valid')]);
        }

        try {
            $contact = $this->handler->handle(new UpdateContactDTO(
                organizerId: $organizerId,
                contactId: 0,
                firstName: $request->validated('first_name'),
                lastName: $request->validated('last_name'),
                phone: $phone,
                emailConsent: (bool) $request->validated('email_consent'),
                smsConsent: (bool) $request->validated('sms_consent'),
            ), $request->validated('email'));
        } catch (ResourceConflictException $exception) {
            throw ValidationException::withMessages(['email' => $exception->getMessage()]);
        }

        return $this->resourceResponse(
            resource: ContactResource::class,
            data: $contact,
            statusCode: ResponseCodes::HTTP_CREATED,
        );
    }
}
