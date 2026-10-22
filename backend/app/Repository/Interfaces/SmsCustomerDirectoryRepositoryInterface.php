<?php

declare(strict_types=1);

namespace HiEvents\Repository\Interfaces;

use HiEvents\Services\Domain\Sms\DTO\SmsCustomerContactDTO;
use HiEvents\Services\Domain\Sms\DTO\SmsReminderAttendeeDTO;
use Illuminate\Support\Collection;

interface SmsCustomerDirectoryRepositoryInterface
{
    /**
     * @return Collection<int, SmsCustomerContactDTO> most recent orders first
     */
    public function findCustomersWithPhone(int $organizerId): Collection;

    /**
     * @return array<string, string> phone => contact name given to the organizer SMS conversation
     */
    public function findConversationNames(int $organizerId): array;

    /**
     * Active attendees of the event with the phone given for them (attendee answer, then order answer, then box office sale).
     *
     * @return Collection<int, SmsReminderAttendeeDTO>
     */
    public function findActiveAttendeesWithPhone(int $eventId, ?int $orderId = null): Collection;

    /**
     * The given attendees of the event, whatever their status, with the phone given for them (same order of precedence).
     *
     * @param  int[]  $attendeeIds
     * @return Collection<int, SmsReminderAttendeeDTO>
     */
    public function findAttendeesWithPhone(int $eventId, array $attendeeIds): Collection;
}
