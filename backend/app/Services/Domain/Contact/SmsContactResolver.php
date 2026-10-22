<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Contact;

use HiEvents\DomainObjects\ContactDomainObject;
use HiEvents\DomainObjects\Enums\ContactSource;
use HiEvents\DomainObjects\Generated\ContactDomainObjectAbstract;
use HiEvents\Helper\PhoneNumberNormalizer;
use HiEvents\Repository\Interfaces\ContactRepositoryInterface;

/**
 * The organizer's contact behind a phone number reached by SMS, created without consent when the person has no contact yet.
 */
class SmsContactResolver
{
    public function __construct(
        private readonly ContactRepositoryInterface $contactRepository,
    ) {}

    public function find(int $organizerId, ?string $phone): ?ContactDomainObject
    {
        $phone = PhoneNumberNormalizer::normalize($phone);
        if ($phone === null) {
            return null;
        }

        /** @var ContactDomainObject|null $contact */
        $contact = $this->contactRepository->findFirstWhere([
            ContactDomainObjectAbstract::ORGANIZER_ID => $organizerId,
            ContactDomainObjectAbstract::PHONE => $phone,
            [ContactDomainObjectAbstract::MERGED_INTO_CONTACT_ID, 'null', null],
        ]);

        return $contact;
    }

    public function create(int $organizerId, string $phone, ?string $name): ContactDomainObject
    {
        $parts = preg_split('/\s+/', trim((string) $name), 2, PREG_SPLIT_NO_EMPTY) ?: [];

        /** @var ContactDomainObject $contact */
        $contact = $this->contactRepository->create([
            ContactDomainObjectAbstract::ORGANIZER_ID => $organizerId,
            ContactDomainObjectAbstract::PHONE => PhoneNumberNormalizer::normalize($phone) ?? $phone,
            ContactDomainObjectAbstract::FIRST_NAME => isset($parts[0]) ? mb_substr($parts[0], 0, 255) : null,
            ContactDomainObjectAbstract::LAST_NAME => isset($parts[1]) ? mb_substr($parts[1], 0, 255) : null,
            ContactDomainObjectAbstract::SOURCE => ContactSource::SMS_CAMPAIGN->name,
        ]);

        /** @var ContactDomainObject $withCode */
        $withCode = $this->contactRepository->findFirst($contact->getId());

        return $withCode;
    }
}
