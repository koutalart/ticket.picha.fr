<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Attendee\AttendanceAccess;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\Generated\AttendeeDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Helper\SmsPhoneNormalizer;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsCustomerDirectoryRepositoryInterface;
use HiEvents\Services\Domain\Attendee\AttendanceAccess\DTO\AttendanceAccessContactDTO;
use HiEvents\Services\Domain\Sms\DTO\SmsReminderAttendeeDTO;

/**
 * Active attendees of one event registered with a given email address or phone number.
 */
class AttendanceAccessContactMatcher
{
    public function __construct(
        private readonly AttendeeRepositoryInterface $attendeeRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly SmsCustomerDirectoryRepositoryInterface $directoryRepository,
    ) {}

    /**
     * @return int[]
     */
    public function findAttendeeIds(int $eventId, AttendanceAccessContactDTO $contact): array
    {
        $ids = $contact->isEmail()
            ? $this->matchEmail($eventId, $contact->value)
            : $this->matchPhone($eventId, $contact->value);

        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    /**
     * @return int[]
     */
    private function matchEmail(int $eventId, string $email): array
    {
        $pattern = addcslashes($email, '\\%_');

        $attendeeIds = $this->attendeeRepository
            ->findWhere([
                AttendeeDomainObjectAbstract::EVENT_ID => $eventId,
                AttendeeDomainObjectAbstract::STATUS => AttendeeStatus::ACTIVE->name,
                [AttendeeDomainObjectAbstract::EMAIL, 'ilike', $pattern],
            ])
            ->map(fn (AttendeeDomainObject $attendee) => $attendee->getId())
            ->all();

        $orderIds = $this->orderRepository
            ->findWhere([
                OrderDomainObjectAbstract::EVENT_ID => $eventId,
                OrderDomainObjectAbstract::STATUS => OrderStatus::COMPLETED->name,
                [OrderDomainObjectAbstract::EMAIL, 'ilike', $pattern],
            ])
            ->map(fn (OrderDomainObject $order) => $order->getId())
            ->all();

        if ($orderIds !== []) {
            $orderAttendeeIds = $this->attendeeRepository
                ->findWhereIn(AttendeeDomainObjectAbstract::ORDER_ID, $orderIds, [
                    AttendeeDomainObjectAbstract::EVENT_ID => $eventId,
                    AttendeeDomainObjectAbstract::STATUS => AttendeeStatus::ACTIVE->name,
                ])
                ->map(fn (AttendeeDomainObject $attendee) => $attendee->getId())
                ->all();

            $attendeeIds = array_merge($attendeeIds, $orderAttendeeIds);
        }

        return $attendeeIds;
    }

    /**
     * @return int[]
     */
    private function matchPhone(int $eventId, string $phone): array
    {
        return $this->directoryRepository
            ->findActiveAttendeesWithPhone($eventId)
            ->filter(fn (SmsReminderAttendeeDTO $attendee) => $attendee->phone !== null
                && SmsPhoneNormalizer::normalize($attendee->phone) === $phone)
            ->map(fn (SmsReminderAttendeeDTO $attendee) => $attendee->attendeeId)
            ->values()
            ->all();
    }
}
