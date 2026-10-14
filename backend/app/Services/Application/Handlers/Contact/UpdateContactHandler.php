<?php

namespace HiEvents\Services\Application\Handlers\Contact;

use HiEvents\DomainObjects\ContactDomainObject;
use HiEvents\DomainObjects\Enums\ContactConsentSource;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Helper\PhoneNumberNormalizer;
use HiEvents\Repository\Interfaces\ContactRepositoryInterface;
use HiEvents\Services\Application\Handlers\Contact\DTO\UpdateContactDTO;

class UpdateContactHandler
{
    public function __construct(
        private readonly ContactLoader $contactLoader,
        private readonly ContactRepositoryInterface $contactRepository,
    ) {}

    /**
     * @throws ResourceNotFoundException
     */
    public function handle(UpdateContactDTO $dto): ContactDomainObject
    {
        $contact = $this->contactLoader->load($dto->organizerId, $dto->contactId);
        $now = now()->toDateTimeString();

        $attributes = [
            ContactDomainObject::FIRST_NAME => $dto->firstName,
            ContactDomainObject::LAST_NAME => $dto->lastName,
            ContactDomainObject::PHONE => PhoneNumberNormalizer::normalize($dto->phone),
        ];

        if ($dto->emailConsent && ! $contact->hasEmailConsent()) {
            $attributes[ContactDomainObject::EMAIL_CONSENT_AT] = $now;
            $attributes[ContactDomainObject::EMAIL_CONSENT_SOURCE] = ContactConsentSource::MANUAL->name;
        } elseif (! $dto->emailConsent && $contact->hasEmailConsent()) {
            $attributes[ContactDomainObject::EMAIL_UNSUBSCRIBED_AT] = $now;
        }

        if ($dto->smsConsent && ! $contact->hasSmsConsent()) {
            $attributes[ContactDomainObject::SMS_CONSENT_AT] = $now;
            $attributes[ContactDomainObject::SMS_CONSENT_SOURCE] = ContactConsentSource::MANUAL->name;
        } elseif (! $dto->smsConsent && $contact->hasSmsConsent()) {
            $attributes[ContactDomainObject::SMS_UNSUBSCRIBED_AT] = $now;
        }

        $this->contactRepository->updateFromArray($contact->getId(), $attributes);

        return $this->contactLoader->load($dto->organizerId, $dto->contactId);
    }
}
