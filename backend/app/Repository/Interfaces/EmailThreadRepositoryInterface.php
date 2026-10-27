<?php

declare(strict_types=1);

namespace HiEvents\Repository\Interfaces;

use HiEvents\DomainObjects\EmailThreadDomainObject;
use HiEvents\Services\Domain\Email\Inbox\DTO\EmailRecipientDTO;
use HiEvents\Services\Domain\Email\Inbox\DTO\EmailThreadSearchDTO;
use HiEvents\Services\Domain\Sms\DTO\SmsCustomerOrderDTO;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * @extends RepositoryInterface<EmailThreadDomainObject>
 */
interface EmailThreadRepositoryInterface extends RepositoryInterface
{
    public function searchForOrganizer(int $organizerId, EmailThreadSearchDTO $search): LengthAwarePaginator;

    public function countUnreadForOrganizer(int $organizerId): int;

    /**
     * Orders of the organizer's events placed with this e-mail address (or with an attendee using it), newest first.
     *
     * @return Collection<int, SmsCustomerOrderDTO>
     */
    public function findOrdersByEmail(int $organizerId, string $email): Collection;

    /**
     * Whether the address belongs to one of the organizer's contacts, buyers or attendees.
     */
    public function isKnownRecipient(int $organizerId, string $email): bool;

    /**
     * Whether the contact with this address unsubscribed from the organizer's e-mails.
     */
    public function isUnsubscribedRecipient(int $organizerId, string $email): bool;

    /**
     * Contacts, buyers and attendees of the organizer matching the search, one row per address.
     *
     * @return Collection<int, EmailRecipientDTO>
     */
    public function searchRecipients(int $organizerId, string $query, int $limit): Collection;

    /**
     * Whether the address belongs to a user of the account (organizer team), whose copies are not logged.
     */
    public function isAccountUserEmail(int $accountId, string $email): bool;

    public function findNameForEmail(int $organizerId, string $email): ?string;
}
