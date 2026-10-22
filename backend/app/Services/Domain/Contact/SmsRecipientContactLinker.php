<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Contact;

use HiEvents\DomainObjects\Generated\QrLinkDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SmsCampaignRecipientDomainObjectAbstract;
use HiEvents\DomainObjects\QrLinkDomainObject;
use HiEvents\DomainObjects\SmsCampaignRecipientDomainObject;
use HiEvents\Helper\PhoneNumberNormalizer;
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
        private readonly SmsContactResolver $contactResolver,
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
                    $contact = $this->contactResolver->find($qrLink->getOrganizerId(), $phone);
                    if ($contact === null) {
                        $contact = $this->contactResolver->create($qrLink->getOrganizerId(), $phone, $recipient->getContactName());
                        $created++;
                    }
                    $contacts[$key] = $contact->getId();
                }

                if ($recipient->getContactId() === null) {
                    $this->recipientRepository->updateFromArray($recipient->getId(), [
                        SmsCampaignRecipientDomainObjectAbstract::CONTACT_ID => $contacts[$key],
                    ]);
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
}
