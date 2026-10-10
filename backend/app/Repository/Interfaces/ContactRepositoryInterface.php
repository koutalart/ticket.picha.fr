<?php

namespace HiEvents\Repository\Interfaces;

use HiEvents\DomainObjects\ContactDomainObject;

/**
 * @extends RepositoryInterface<ContactDomainObject>
 */
interface ContactRepositoryInterface extends RepositoryInterface
{
    /**
     * Rebuilds contacts of an organizer from completed orders and their attendees.
     * When $emails is null, every buyer and attendee of the organizer is synced.
     *
     * @param  string[]|null  $emails  normalized (trimmed, lower-case) e-mail addresses
     */
    public function syncFromOrders(int $organizerId, ?array $emails = null): int;
}
