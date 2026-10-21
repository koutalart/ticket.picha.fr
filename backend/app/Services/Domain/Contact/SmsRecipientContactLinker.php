<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Contact;

use HiEvents\DomainObjects\ContactDomainObject;
use HiEvents\DomainObjects\Enums\ContactSource;
use HiEvents\DomainObjects\Generated\ContactDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\QrLinkDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SmsCampaignRecipientDomainObjectAbstract;
use HiEvents\DomainObjects\QrLinkDomainObject;
use HiEvents\DomainObjects\SmsCampaignRecipientDomainObject;
use HiEvents\Helper\PhoneNumberNormalizer;
use HiEvents\Repository\Interfaces\ContactRepositoryInterface;
use HiEvents\Repository\Interfaces\QrLinkRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsCampaignRecipientRepositoryInterface;
use HiEvents\Services\Domain\Contact\DTO\SmsRecipientContactLinkResultDTO;
use Illuminate\Database\DatabaseManager;

/**
 * Ties each ?qr= link already sent in an SMS campaign to the contact of its recipient, so those links keep counting for the same person.
 */
class SmsRecipientContactLinker
{
    public function __construct(
        private readonly QrLinkRepositoryInterface $qrLinkRepository,
        private readonly SmsCampaignRecipientRepositoryInterface $recipientRepository,
        private readonly ContactRepositoryInterface $contactRepository,
        private readonly DatabaseManager $databaseManager,
    ) {}

    public function link(?int $organizerId = null): SmsRecipientContactLinkResultDTO
    {
        return $this->databaseManager->transaction(function () use ($organizerId) {
            $where = [
                [QrLinkDomainObjectAbstract::SMS_CAMPAIGN_ID, 'not null', null],
                [QrLinkDomainObjectAbstract::CONTACT_ID, 'null', null],
            ];
            if ($organizerId !== null) {
                $where[QrLinkDomainObjectAbstract::ORGANIZER_ID] = $organizerId;
            }

            $qrLinks = $this->qrLinkRepository->findWhere($where);
            if ($qrLinks->isEmpty()) {
                return new SmsRecipientContactLinkResultDTO(0, 0, 0);
            }

            $recipients = $this->recipientRepository
                ->findWhereIn(SmsCampaignRecipientDomainObjectAbstract::QR_LINK_ID, $qrLinks->map(fn (QrLinkDomainObject $qrLink) => $qrLink->getId())->all())
                ->keyBy(fn (SmsCampaignRecipientDomainObject $recipient) => $recipient->getQrLinkId());

            $linked = 0;
            $created = 0;
            $skipped = 0;
            $contacts = [];

            foreach ($qrLinks as $qrLink) {
                /** @var SmsCampaignRecipientDomainObject|null $recipient */
                $recipient = $recipients->get($qrLink->getId());
                $phone = $recipient !== null ? PhoneNumberNormalizer::normalize($recipient->getPhone()) : null;
                if ($phone === null) {
                    $skipped++;

                    continue;
                }

                $key = $qrLink->getOrganizerId().'|'.$phone;
                if (! isset($contacts[$key])) {
                    $contact = $this->findContact($qrLink->getOrganizerId(), $phone);
                    if ($contact === null) {
                        $contact = $this->createContact($qrLink->getOrganizerId(), $phone, $recipient->getContactName());
                        $created++;
                    }
                    $contacts[$key] = $contact->getId();
                }

                $this->qrLinkRepository->updateWhere(
                    attributes: [QrLinkDomainObjectAbstract::CONTACT_ID => $contacts[$key]],
                    where: [QrLinkDomainObjectAbstract::ID => $qrLink->getId()],
                );
                $linked++;
            }

            return new SmsRecipientContactLinkResultDTO($linked, $created, $skipped);
        });
    }

    private function findContact(int $organizerId, string $phone): ?ContactDomainObject
    {
        /** @var ContactDomainObject|null $contact */
        $contact = $this->contactRepository->findFirstWhere([
            ContactDomainObjectAbstract::ORGANIZER_ID => $organizerId,
            ContactDomainObjectAbstract::PHONE => $phone,
            [ContactDomainObjectAbstract::MERGED_INTO_CONTACT_ID, 'null', null],
        ]);

        return $contact;
    }

    private function createContact(int $organizerId, string $phone, ?string $name): ContactDomainObject
    {
        $parts = preg_split('/\s+/', trim((string) $name), 2, PREG_SPLIT_NO_EMPTY) ?: [];

        /** @var ContactDomainObject $contact */
        $contact = $this->contactRepository->create([
            ContactDomainObjectAbstract::ORGANIZER_ID => $organizerId,
            ContactDomainObjectAbstract::PHONE => $phone,
            ContactDomainObjectAbstract::FIRST_NAME => isset($parts[0]) ? mb_substr($parts[0], 0, 255) : null,
            ContactDomainObjectAbstract::LAST_NAME => isset($parts[1]) ? mb_substr($parts[1], 0, 255) : null,
            ContactDomainObjectAbstract::SOURCE => ContactSource::SMS_CAMPAIGN->name,
        ]);

        return $contact;
    }
}
