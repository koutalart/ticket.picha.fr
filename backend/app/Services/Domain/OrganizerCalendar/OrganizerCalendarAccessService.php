<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\OrganizerCalendar;

use HiEvents\DomainObjects\Generated\OrganizerCalendarAccessDomainObjectAbstract as Access;
use HiEvents\DomainObjects\OrganizerCalendarAccessDomainObject;
use HiEvents\Exceptions\OrganizerCalendarAccessDeniedException;
use HiEvents\Helper\CustomerLocale;
use HiEvents\Repository\Interfaces\OrganizerCalendarAccessRepositoryInterface;
use HiEvents\Services\Domain\OrganizerCalendar\DTO\OrganizerCalendarAccessGrantDTO;
use Illuminate\Support\Carbon;

/**
 * Access keys are random, given once to the browser and stored only as an HMAC.
 * A key keeps its device recognised for a year, renewed on every visit, until it is revoked.
 */
class OrganizerCalendarAccessService
{
    public const TTL_SECONDS = 31536000;

    public const MEDIA_URL_TTL_SECONDS = 43200;

    private const RENEW_AFTER_SECONDS = 86400;

    public function __construct(
        private readonly OrganizerCalendarAccessRepositoryInterface $accessRepository,
    ) {}

    public function grant(int $calendarId, ?string $visitorName = null, ?string $deviceId = null): OrganizerCalendarAccessGrantDTO
    {
        $key = bin2hex(random_bytes(32));
        $expiresAt = Carbon::now()->addSeconds(self::TTL_SECONDS)->startOfSecond();

        /** @var OrganizerCalendarAccessDomainObject $access */
        $access = $this->accessRepository->create([
            Access::ORGANIZER_CALENDAR_ID => $calendarId,
            Access::KEY_HASH => $this->hashKey($key),
            Access::EXPIRES_AT => $expiresAt->toDateTimeString(),
            Access::VISITOR_NAME => $visitorName,
            Access::DEVICE_ID => $deviceId,
        ]);

        return new OrganizerCalendarAccessGrantDTO(
            accessId: $access->getId(),
            accessKey: $key,
            expiresAt: $expiresAt,
        );
    }

    /**
     * @throws OrganizerCalendarAccessDeniedException
     */
    public function resolve(int $calendarId, ?string $key): OrganizerCalendarAccessDomainObject
    {
        $key = trim((string) $key);

        $access = preg_match('/^[a-f0-9]{64}$/', $key) === 1
            ? $this->accessRepository->findFirstWhere([Access::KEY_HASH => $this->hashKey($key)])
            : null;

        if (! $access instanceof OrganizerCalendarAccessDomainObject
            || ! hash_equals($access->getKeyHash(), $this->hashKey($key))
            || ! $this->isActive($access, $calendarId)) {
            throw new OrganizerCalendarAccessDeniedException(
                __('Your access has expired or is invalid. Request a new code.', [], CustomerLocale::get())
            );
        }

        return $this->renew($access);
    }

    public function mediaUrlsExpireAt(OrganizerCalendarAccessDomainObject $access): Carbon
    {
        $accessExpiry = Carbon::parse($access->getExpiresAt());
        $mediaExpiry = Carbon::now()->addSeconds(self::MEDIA_URL_TTL_SECONDS)->startOfSecond();

        return $accessExpiry->lessThan($mediaExpiry) ? $accessExpiry : $mediaExpiry;
    }

    private function renew(OrganizerCalendarAccessDomainObject $access): OrganizerCalendarAccessDomainObject
    {
        $expiresAt = Carbon::now()->addSeconds(self::TTL_SECONDS)->startOfSecond();

        if ($expiresAt->getTimestamp() - Carbon::parse($access->getExpiresAt())->getTimestamp() < self::RENEW_AFTER_SECONDS) {
            return $access;
        }

        $this->accessRepository->updateWhere(
            [Access::EXPIRES_AT => $expiresAt->toDateTimeString()],
            [Access::ID => $access->getId(), [Access::REVOKED_AT, 'null', null]],
        );

        return $access->setExpiresAt($expiresAt->toDateTimeString());
    }

    public function isActiveById(int $accessId, int $calendarId): bool
    {
        $access = $this->accessRepository->findFirstWhere([Access::ID => $accessId]);

        return $access instanceof OrganizerCalendarAccessDomainObject && $this->isActive($access, $calendarId);
    }

    public function revokeAll(int $calendarId): int
    {
        $now = Carbon::now()->toDateTimeString();

        return $this->accessRepository->updateWhere(
            [Access::REVOKED_AT => $now],
            [
                Access::ORGANIZER_CALENDAR_ID => $calendarId,
                [Access::REVOKED_AT, 'null', null],
                [Access::EXPIRES_AT, '>', $now],
            ],
        );
    }

    private function isActive(OrganizerCalendarAccessDomainObject $access, int $calendarId): bool
    {
        return $access->getOrganizerCalendarId() === $calendarId
            && $access->getRevokedAt() === null
            && Carbon::parse($access->getExpiresAt())->isFuture();
    }

    private function hashKey(string $key): string
    {
        return hash_hmac('sha256', $key, 'organizer-calendar-access|'.config('app.key'));
    }
}
