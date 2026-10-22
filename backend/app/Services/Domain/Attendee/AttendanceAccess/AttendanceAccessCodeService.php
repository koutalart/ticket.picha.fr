<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Attendee\AttendanceAccess;

use Carbon\Carbon;
use HiEvents\Exceptions\InvalidAttendanceAccessCodeException;
use HiEvents\Helper\CustomerLocale;
use HiEvents\Services\Domain\Attendee\AttendanceAccess\DTO\AttendanceAccessContactDTO;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * One-time 6 digit codes, stored hashed in the cache for 10 minutes, at most 5 attempts.
 */
class AttendanceAccessCodeService
{
    public const CODE_LENGTH = 6;

    public const TTL_SECONDS = 600;

    public const MAX_ATTEMPTS = 5;

    private const KEY_PREFIX = 'attendance-access:code:';

    public function __construct(
        private readonly CacheRepository $cache,
    ) {}

    public function contactKey(int $eventId, AttendanceAccessContactDTO $contact): string
    {
        return hash_hmac('sha256', $eventId.'|'.$contact->channel->value.'|'.$contact->value, $this->secret());
    }

    /**
     * @param  int[]  $attendeeIds
     */
    public function issue(int $eventId, AttendanceAccessContactDTO $contact, array $attendeeIds): string
    {
        $code = str_pad((string) random_int(0, 10 ** self::CODE_LENGTH - 1), self::CODE_LENGTH, '0', STR_PAD_LEFT);
        $contactKey = $this->contactKey($eventId, $contact);

        $this->cache->put(self::KEY_PREFIX.$contactKey, [
            'hash' => $this->hashCode($contactKey, $code),
            'attendee_ids' => array_values($attendeeIds),
            'attempts' => 0,
            'expires_at' => Carbon::now()->addSeconds(self::TTL_SECONDS)->getTimestamp(),
        ], self::TTL_SECONDS);

        return $code;
    }

    /**
     * @return int[]
     *
     * @throws InvalidAttendanceAccessCodeException
     */
    public function consume(int $eventId, AttendanceAccessContactDTO $contact, string $code): array
    {
        $contactKey = $this->contactKey($eventId, $contact);

        return $this->withLock($contactKey, fn () => $this->consumeUnlocked($contactKey, $code));
    }

    /**
     * @return int[]
     *
     * @throws InvalidAttendanceAccessCodeException
     */
    private function consumeUnlocked(string $contactKey, string $code): array
    {
        $key = self::KEY_PREFIX.$contactKey;
        $entry = $this->cache->get($key);

        if (! is_array($entry) || ($entry['expires_at'] ?? 0) < Carbon::now()->getTimestamp()) {
            $this->cache->forget($key);
            throw $this->invalid();
        }

        $normalizedCode = preg_replace('/\D+/', '', $code) ?? '';

        if (strlen($normalizedCode) !== self::CODE_LENGTH
            || ! hash_equals((string) $entry['hash'], $this->hashCode($contactKey, $normalizedCode))) {
            $attempts = (int) $entry['attempts'] + 1;

            if ($attempts >= self::MAX_ATTEMPTS) {
                $this->cache->forget($key);
            } else {
                $entry['attempts'] = $attempts;
                $this->cache->put($key, $entry, max(1, (int) $entry['expires_at'] - Carbon::now()->getTimestamp()));
            }

            throw $this->invalid();
        }

        $this->cache->forget($key);

        return array_map('intval', (array) $entry['attendee_ids']);
    }

    private function withLock(string $contactKey, callable $callback): mixed
    {
        $store = $this->cache->getStore();

        if (! $store instanceof LockProvider) {
            return $callback();
        }

        try {
            return $store->lock(self::KEY_PREFIX.'lock:'.$contactKey, 10)->block(5, $callback);
        } catch (LockTimeoutException) {
            throw $this->invalid();
        }
    }

    private function hashCode(string $contactKey, string $code): string
    {
        return hash_hmac('sha256', $contactKey.'|'.$code, $this->secret());
    }

    private function secret(): string
    {
        return 'attendance-access|'.config('app.key');
    }

    private function invalid(): InvalidAttendanceAccessCodeException
    {
        return new InvalidAttendanceAccessCodeException(
            __('This code is incorrect or has expired. Request a new code.', [], CustomerLocale::get())
        );
    }
}
