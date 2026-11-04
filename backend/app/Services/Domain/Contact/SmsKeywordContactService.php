<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Contact;

use HiEvents\DomainObjects\ContactDomainObject;
use HiEvents\DomainObjects\ContactTagDomainObject;
use HiEvents\DomainObjects\Enums\ContactConsentSource;
use HiEvents\DomainObjects\Enums\ContactSource;
use HiEvents\DomainObjects\Generated\ContactDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\ContactTagAssignmentDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\ContactTagDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SmsOptOutDomainObjectAbstract;
use HiEvents\Repository\Interfaces\ContactRepositoryInterface;
use HiEvents\Repository\Interfaces\ContactTagAssignmentRepositoryInterface;
use HiEvents\Repository\Interfaces\ContactTagRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsOptOutRepositoryInterface;
use Illuminate\Support\Carbon;

/**
 * The contact of an organizer behind a number that texted one of its SMS keywords: created when unknown, tagged
 * « SMS <KEYWORD> », and given SMS consent unless the person already said STOP to this organizer.
 */
class SmsKeywordContactService
{
    public function __construct(
        private readonly SmsContactResolver $contactResolver,
        private readonly ContactRepositoryInterface $contactRepository,
        private readonly ContactTagRepositoryInterface $tagRepository,
        private readonly ContactTagAssignmentRepositoryInterface $tagAssignmentRepository,
        private readonly SmsOptOutRepositoryInterface $optOutRepository,
    ) {}

    public static function tagName(string $keyword): string
    {
        return 'SMS '.$keyword;
    }

    public function recordKeywordContact(int $organizerId, string $phone, string $keyword): ContactDomainObject
    {
        $contact = $this->contactResolver->find($organizerId, $phone)
            ?? $this->contactResolver->create($organizerId, $phone, null, ContactSource::SMS_KEYWORD);

        if ($contact->getSmsConsentAt() === null && $contact->getSmsUnsubscribedAt() === null && ! $this->isOptedOut($organizerId, $phone)) {
            /** @var ContactDomainObject $contact */
            $contact = $this->contactRepository->updateFromArray($contact->getId(), [
                ContactDomainObjectAbstract::SMS_CONSENT_AT => Carbon::now('UTC')->toDateTimeString(),
                ContactDomainObjectAbstract::SMS_CONSENT_SOURCE => ContactConsentSource::SMS_KEYWORD->name,
            ]);
        }

        $this->tag($contact, self::tagName($keyword));

        return $contact;
    }

    private function tag(ContactDomainObject $contact, string $name): void
    {
        /** @var ContactTagDomainObject|null $tag */
        $tag = $this->tagRepository->findFirstWhere([
            ContactTagDomainObjectAbstract::ORGANIZER_ID => $contact->getOrganizerId(),
            ContactTagDomainObjectAbstract::NAME => $name,
        ]);

        $tag ??= $this->tagRepository->create([
            ContactTagDomainObjectAbstract::ORGANIZER_ID => $contact->getOrganizerId(),
            ContactTagDomainObjectAbstract::NAME => $name,
        ]);

        $assignment = [
            ContactTagAssignmentDomainObjectAbstract::CONTACT_ID => $contact->getId(),
            ContactTagAssignmentDomainObjectAbstract::CONTACT_TAG_ID => $tag->getId(),
        ];

        if ($this->tagAssignmentRepository->findFirstWhere($assignment) === null) {
            $this->tagAssignmentRepository->create($assignment);
        }
    }

    private function isOptedOut(int $organizerId, string $phone): bool
    {
        return $this->optOutRepository->findFirstWhere([
            SmsOptOutDomainObjectAbstract::ORGANIZER_ID => $organizerId,
            SmsOptOutDomainObjectAbstract::PHONE => $phone,
        ]) !== null;
    }
}
