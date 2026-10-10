<?php

namespace HiEvents\Services\Application\Handlers\Contact;

use HiEvents\DomainObjects\ContactDomainObject;
use HiEvents\DomainObjects\Enums\ContactConsentSource;
use HiEvents\DomainObjects\Enums\ContactSource;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Helper\PhoneNumberNormalizer;
use HiEvents\Repository\Interfaces\ContactRepositoryInterface;
use HiEvents\Services\Application\Handlers\Contact\DTO\UpdateContactDTO;
use HiEvents\Services\Domain\Contact\ContactSyncService;

class CreateContactHandler
{
    public function __construct(
        private readonly ContactRepositoryInterface $contactRepository,
        private readonly ContactLoader $contactLoader,
    ) {}

    /**
     * @throws ResourceConflictException
     * @throws ResourceNotFoundException
     */
    public function handle(UpdateContactDTO $dto, ?string $email): ContactDomainObject
    {
        $email = ContactSyncService::normalizeEmail($email);
        $phone = PhoneNumberNormalizer::normalize($dto->phone);

        if ($email !== null && $this->contactRepository->findFirstWhere([
            ContactDomainObject::ORGANIZER_ID => $dto->organizerId,
            ContactDomainObject::EMAIL => $email,
        ]) !== null) {
            throw new ResourceConflictException(__('A contact with this email already exists'));
        }

        $now = now()->toDateTimeString();

        $contact = $this->contactRepository->create([
            ContactDomainObject::ORGANIZER_ID => $dto->organizerId,
            ContactDomainObject::FIRST_NAME => $dto->firstName,
            ContactDomainObject::LAST_NAME => $dto->lastName,
            ContactDomainObject::EMAIL => $email,
            ContactDomainObject::PHONE => $phone,
            ContactDomainObject::SOURCE => ContactSource::MANUAL->name,
            ContactDomainObject::EMAIL_CONSENT_AT => $dto->emailConsent && $email !== null ? $now : null,
            ContactDomainObject::EMAIL_CONSENT_SOURCE => $dto->emailConsent && $email !== null ? ContactConsentSource::MANUAL->name : null,
            ContactDomainObject::SMS_CONSENT_AT => $dto->smsConsent && $phone !== null ? $now : null,
            ContactDomainObject::SMS_CONSENT_SOURCE => $dto->smsConsent && $phone !== null ? ContactConsentSource::MANUAL->name : null,
        ]);

        return $this->contactLoader->load($dto->organizerId, $contact->getId());
    }
}
