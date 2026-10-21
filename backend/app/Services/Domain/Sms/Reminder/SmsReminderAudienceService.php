<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms\Reminder;

use HiEvents\DomainObjects\Generated\SmsOptOutDomainObjectAbstract;
use HiEvents\DomainObjects\SmsOptOutDomainObject;
use HiEvents\Helper\SmsPhoneNormalizer;
use HiEvents\Repository\Interfaces\SmsCustomerDirectoryRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsOptOutRepositoryInterface;
use HiEvents\Services\Domain\Sms\DTO\SmsReminderAudienceDTO;
use HiEvents\Services\Domain\Sms\DTO\SmsReminderRecipientDTO;

/**
 * Active attendees of an event reachable by SMS: valid number, not unsubscribed, one SMS per number.
 */
class SmsReminderAudienceService
{
    public function __construct(
        private readonly SmsCustomerDirectoryRepositoryInterface $directoryRepository,
        private readonly SmsOptOutRepositoryInterface $optOutRepository,
    ) {}

    public function build(int $organizerId, int $eventId): SmsReminderAudienceDTO
    {
        $attendees = $this->directoryRepository->findActiveAttendeesWithPhone($eventId);
        $optedOut = $this->optOutRepository
            ->findWhere([SmsOptOutDomainObjectAbstract::ORGANIZER_ID => $organizerId])
            ->mapWithKeys(fn (SmsOptOutDomainObject $optOut) => [$optOut->getPhone() => true])
            ->all();

        $recipients = [];
        $withoutValidPhone = 0;
        $optedOutCount = 0;
        $sharedPhone = 0;

        foreach ($attendees as $attendee) {
            $phone = $attendee->phone !== null ? SmsPhoneNormalizer::normalize($attendee->phone) : null;

            if ($phone === null) {
                $withoutValidPhone++;

                continue;
            }

            if (isset($optedOut[$phone])) {
                $optedOutCount++;

                continue;
            }

            if (isset($recipients[$phone])) {
                $sharedPhone++;

                continue;
            }

            $contactName = trim(($attendee->firstName ?? '').' '.($attendee->lastName ?? ''));
            $recipients[$phone] = new SmsReminderRecipientDTO(
                phone: $phone,
                attendeeId: $attendee->attendeeId,
                firstName: $attendee->firstName,
                contactName: $contactName !== '' ? mb_substr($contactName, 0, 150) : null,
            );
        }

        return new SmsReminderAudienceDTO(
            recipients: array_values($recipients),
            activeAttendees: $attendees->count(),
            withoutValidPhone: $withoutValidPhone,
            optedOut: $optedOutCount,
            sharedPhone: $sharedPhone,
        );
    }
}
