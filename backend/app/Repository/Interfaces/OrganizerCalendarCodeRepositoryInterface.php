<?php

declare(strict_types=1);

namespace HiEvents\Repository\Interfaces;

use HiEvents\DomainObjects\OrganizerCalendarCodeDomainObject;

/**
 * @extends RepositoryInterface<OrganizerCalendarCodeDomainObject>
 */
interface OrganizerCalendarCodeRepositoryInterface extends RepositoryInterface
{
    /**
     * The most recent code of the calendar that is neither used, expired nor out of attempts.
     */
    public function findLatestActive(int $calendarId, string $now, int $maxAttempts): ?OrganizerCalendarCodeDomainObject;

    /**
     * Counts one attempt on the code, atomically, only while it has attempts left. False when it had none.
     */
    public function claimAttempt(int $codeId, int $maxAttempts): bool;

    /**
     * Marks the code used, atomically. False when it was already used.
     */
    public function markUsed(int $codeId, string $usedAt): bool;

    public function findOldestCreatedAtSince(int $calendarId, string $since): ?string;

    public function findLatestCreatedAt(int $calendarId): ?string;
}
