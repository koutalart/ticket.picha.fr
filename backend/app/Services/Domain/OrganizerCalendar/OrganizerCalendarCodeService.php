<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\OrganizerCalendar;

use HiEvents\DomainObjects\Generated\OrganizerCalendarCodeDomainObjectAbstract as Code;
use HiEvents\DomainObjects\OrganizerCalendarCodeDomainObject;
use HiEvents\DomainObjects\OrganizerCalendarDomainObject;
use HiEvents\Exceptions\InvalidOrganizerCalendarCodeException;
use HiEvents\Exceptions\OrganizerCalendarRateLimitedException;
use HiEvents\Helper\CustomerLocale;
use HiEvents\Repository\Interfaces\OrganizerCalendarCodeRepositoryInterface;
use HiEvents\Services\Domain\OrganizerCalendar\DTO\IssuedOrganizerCalendarCodeDTO;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Carbon;

/**
 * One-time 6 digit codes stored as an HMAC, valid 15 minutes, 5 attempts each.
 * A calendar gets at most one code every 5 minutes and 5 unused codes per day (Mayotte time).
 * Codes that led to a successful login do not count.
 */
class OrganizerCalendarCodeService
{
    public const CODE_LENGTH = 6;

    public const TTL_SECONDS = 900;

    public const MAX_ATTEMPTS = 5;

    public const MIN_INTERVAL_SECONDS = 300;

    public const MAX_CODES_PER_DAY = 5;

    private const DAY_SECONDS = 86400;

    private const DAY_TIMEZONE = 'Indian/Mayotte';

    public function __construct(
        private readonly OrganizerCalendarCodeRepositoryInterface $codeRepository,
        private readonly CacheRepository $cache,
    ) {}

    /**
     * @throws OrganizerCalendarRateLimitedException
     */
    public function issue(OrganizerCalendarDomainObject $calendar): IssuedOrganizerCalendarCodeDTO
    {
        return $this->withLock($calendar->getId(), function () use ($calendar): IssuedOrganizerCalendarCodeDTO {
            $this->assertCanIssue($calendar->getId());

            $now = Carbon::now();
            $this->codeRepository->updateWhere(
                [Code::EXPIRES_AT => $now->toDateTimeString()],
                [
                    Code::ORGANIZER_CALENDAR_ID => $calendar->getId(),
                    [Code::USED_AT, 'null', null],
                    [Code::EXPIRES_AT, '>', $now->toDateTimeString()],
                ],
            );

            $code = str_pad((string) random_int(0, 10 ** self::CODE_LENGTH - 1), self::CODE_LENGTH, '0', STR_PAD_LEFT);

            /** @var OrganizerCalendarCodeDomainObject $record */
            $record = $this->codeRepository->create([
                Code::ORGANIZER_CALENDAR_ID => $calendar->getId(),
                Code::CODE_HASH => $this->hashCode($calendar->getId(), $code),
                Code::EXPIRES_AT => $now->copy()->addSeconds(self::TTL_SECONDS)->toDateTimeString(),
                Code::ATTEMPTS => 0,
            ]);

            return new IssuedOrganizerCalendarCodeDTO(
                codeId: $record->getId(),
                code: $code,
                validitySeconds: self::TTL_SECONDS,
            );
        });
    }

    /**
     * Forgets a code that could not be delivered, so it neither counts against the limits nor stays usable.
     */
    public function discard(int $codeId): void
    {
        $this->codeRepository->deleteWhere([Code::ID => $codeId]);
    }

    /**
     * @throws InvalidOrganizerCalendarCodeException
     */
    public function verify(OrganizerCalendarDomainObject $calendar, string $submittedCode): void
    {
        $now = Carbon::now()->toDateTimeString();
        $code = $this->codeRepository->findLatestActive($calendar->getId(), $now, self::MAX_ATTEMPTS);

        if ($code === null || ! $this->codeRepository->claimAttempt($code->getId(), self::MAX_ATTEMPTS)) {
            throw $this->invalid();
        }

        $normalized = preg_replace('/\D+/', '', $submittedCode) ?? '';
        $matches = strlen($normalized) === self::CODE_LENGTH
            && hash_equals($code->getCodeHash(), $this->hashCode($calendar->getId(), $normalized));

        if (! $matches || ! $this->codeRepository->markUsed($code->getId(), $now)) {
            throw $this->invalid();
        }
    }

    public function expireAll(int $calendarId): int
    {
        $now = Carbon::now()->toDateTimeString();

        return $this->codeRepository->updateWhere(
            [Code::EXPIRES_AT => $now],
            [
                Code::ORGANIZER_CALENDAR_ID => $calendarId,
                [Code::USED_AT, 'null', null],
                [Code::EXPIRES_AT, '>', $now],
            ],
        );
    }

    public function hashCode(int $calendarId, string $code): string
    {
        return hash_hmac('sha256', $calendarId.'|'.$code, 'organizer-calendar-code|'.config('app.key'));
    }

    /**
     * @throws OrganizerCalendarRateLimitedException
     */
    private function assertCanIssue(int $calendarId): void
    {
        $now = Carbon::now();

        $latest = $this->codeRepository->findLatestCreatedAt($calendarId);
        if ($latest !== null) {
            $elapsed = $now->getTimestamp() - Carbon::parse($latest)->getTimestamp();
            if ($elapsed < self::MIN_INTERVAL_SECONDS) {
                throw $this->limited(
                    'A code was sent less than 5 minutes ago. Use the last SMS received or try again in :delay.',
                    self::MIN_INTERVAL_SECONDS - $elapsed,
                );
            }
        }

        $since = $now->copy()->setTimezone(self::DAY_TIMEZONE)->startOfDay()->utc()->toDateTimeString();
        $countToday = $this->codeRepository->countWhere([
            Code::ORGANIZER_CALENDAR_ID => $calendarId,
            [Code::CREATED_AT, '>=', $since],
            [Code::USED_AT, 'null', null],
        ]);

        if ($countToday >= self::MAX_CODES_PER_DAY) {
            $retryAfter = max(
                60,
                $now->copy()->setTimezone(self::DAY_TIMEZONE)->addDay()->startOfDay()->getTimestamp() - $now->getTimestamp(),
            );

            throw $this->limited('Too many codes requested today. Try again in :delay.', $retryAfter);
        }
    }

    private function withLock(int $calendarId, callable $callback): mixed
    {
        $store = $this->cache->getStore();

        if (! $store instanceof LockProvider) {
            return $callback();
        }

        try {
            return $store->lock('organizer-calendar-code:'.$calendarId, 10)->block(5, $callback);
        } catch (LockTimeoutException) {
            throw $this->limited('A code was sent less than 5 minutes ago. Use the last SMS received or try again in :delay.', 60);
        }
    }

    private function limited(string $message, int $retryAfterSeconds): OrganizerCalendarRateLimitedException
    {
        $retryAfterSeconds = max(1, $retryAfterSeconds);

        return new OrganizerCalendarRateLimitedException(
            __($message, ['delay' => self::formatDelay($retryAfterSeconds)], CustomerLocale::get()),
            $retryAfterSeconds,
        );
    }

    public static function formatDelay(int $seconds): string
    {
        $minutes = max(1, (int) ceil($seconds / 60));

        if ($minutes < 60) {
            return $minutes.' min';
        }

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return $rest === 0 ? $hours.' h' : $hours.' h '.$rest.' min';
    }

    private function invalid(): InvalidOrganizerCalendarCodeException
    {
        return new InvalidOrganizerCalendarCodeException(
            __('This code is incorrect or has expired. Request a new code.', [], CustomerLocale::get())
        );
    }
}
