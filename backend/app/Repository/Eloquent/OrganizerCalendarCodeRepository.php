<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\Generated\OrganizerCalendarCodeDomainObjectAbstract as Code;
use HiEvents\DomainObjects\OrganizerCalendarCodeDomainObject;
use HiEvents\Models\OrganizerCalendarCode;
use HiEvents\Repository\Interfaces\OrganizerCalendarCodeRepositoryInterface;

/**
 * @extends BaseRepository<OrganizerCalendarCodeDomainObject>
 */
class OrganizerCalendarCodeRepository extends BaseRepository implements OrganizerCalendarCodeRepositoryInterface
{
    protected function getModel(): string
    {
        return OrganizerCalendarCode::class;
    }

    public function getDomainObject(): string
    {
        return OrganizerCalendarCodeDomainObject::class;
    }

    public function findLatestActive(int $calendarId, string $now, int $maxAttempts): ?OrganizerCalendarCodeDomainObject
    {
        $code = OrganizerCalendarCode::query()
            ->where(Code::ORGANIZER_CALENDAR_ID, $calendarId)
            ->whereNull(Code::USED_AT)
            ->where(Code::EXPIRES_AT, '>', $now)
            ->where(Code::ATTEMPTS, '<', $maxAttempts)
            ->orderByDesc(Code::ID)
            ->first();

        return $this->handleSingleResult($code);
    }

    public function claimAttempt(int $codeId, int $maxAttempts): bool
    {
        return OrganizerCalendarCode::query()
            ->whereKey($codeId)
            ->where(Code::ATTEMPTS, '<', $maxAttempts)
            ->increment(Code::ATTEMPTS) === 1;
    }

    public function markUsed(int $codeId, string $usedAt): bool
    {
        return OrganizerCalendarCode::query()
            ->whereKey($codeId)
            ->whereNull(Code::USED_AT)
            ->update([Code::USED_AT => $usedAt]) === 1;
    }

    public function findOldestCreatedAtSince(int $calendarId, string $since): ?string
    {
        $value = OrganizerCalendarCode::query()
            ->where(Code::ORGANIZER_CALENDAR_ID, $calendarId)
            ->where(Code::CREATED_AT, '>=', $since)
            ->min(Code::CREATED_AT);

        return $value === null ? null : (string) $value;
    }

    public function findLatestCreatedAt(int $calendarId): ?string
    {
        $value = OrganizerCalendarCode::query()
            ->where(Code::ORGANIZER_CALENDAR_ID, $calendarId)
            ->max(Code::CREATED_AT);

        return $value === null ? null : (string) $value;
    }
}
