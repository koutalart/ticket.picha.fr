<?php

namespace Tests\Unit\Services\Domain\Attendee\AttendanceAccess;

use Carbon\Carbon;
use HiEvents\DomainObjects\Enums\AttendanceAccessChannel;
use HiEvents\Exceptions\InvalidAttendanceAccessCodeException;
use HiEvents\Services\Domain\Attendee\AttendanceAccess\AttendanceAccessCodeService;
use HiEvents\Services\Domain\Attendee\AttendanceAccess\DTO\AttendanceAccessContactDTO;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Tests\TestCase;

class AttendanceAccessCodeServiceTest extends TestCase
{
    private Repository $cache;

    private AttendanceAccessCodeService $service;

    private AttendanceAccessContactDTO $contact;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:dGVzdC1rZXktZm9yLWF0dGVuZGFuY2UtYWNjZXNz']);
        Carbon::setTestNow('2026-10-22 10:00:00');

        $this->cache = new Repository(new ArrayStore);
        $this->service = new AttendanceAccessCodeService($this->cache);
        $this->contact = new AttendanceAccessContactDTO(AttendanceAccessChannel::EMAIL, 'jo@example.com');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_issued_code_has_six_digits_and_returns_the_attendees(): void
    {
        $code = $this->service->issue(4, $this->contact, [12, 13]);

        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $this->assertSame([12, 13], $this->service->consume(4, $this->contact, $code));
    }

    public function test_code_is_single_use(): void
    {
        $code = $this->service->issue(4, $this->contact, [12]);
        $this->service->consume(4, $this->contact, $code);

        $this->expectException(InvalidAttendanceAccessCodeException::class);
        $this->service->consume(4, $this->contact, $code);
    }

    public function test_code_expires_after_ten_minutes(): void
    {
        $code = $this->service->issue(4, $this->contact, [12]);

        Carbon::setTestNow('2026-10-22 10:10:01');

        $this->expectException(InvalidAttendanceAccessCodeException::class);
        $this->service->consume(4, $this->contact, $code);
    }

    public function test_code_is_still_valid_just_before_expiry(): void
    {
        $code = $this->service->issue(4, $this->contact, [12]);

        Carbon::setTestNow('2026-10-22 10:09:50');

        $this->assertSame([12], $this->service->consume(4, $this->contact, $code));
    }

    public function test_code_is_invalidated_after_five_wrong_attempts(): void
    {
        $code = $this->service->issue(4, $this->contact, [12]);
        $wrong = $code === '000000' ? '111111' : '000000';

        for ($attempt = 0; $attempt < AttendanceAccessCodeService::MAX_ATTEMPTS; $attempt++) {
            try {
                $this->service->consume(4, $this->contact, $wrong);
                $this->fail('A wrong code must be rejected');
            } catch (InvalidAttendanceAccessCodeException) {
            }
        }

        $this->expectException(InvalidAttendanceAccessCodeException::class);
        $this->service->consume(4, $this->contact, $code);
    }

    public function test_wrong_attempts_below_the_limit_keep_the_code(): void
    {
        $code = $this->service->issue(4, $this->contact, [12]);
        $wrong = $code === '000000' ? '111111' : '000000';

        for ($attempt = 0; $attempt < AttendanceAccessCodeService::MAX_ATTEMPTS - 1; $attempt++) {
            try {
                $this->service->consume(4, $this->contact, $wrong);
            } catch (InvalidAttendanceAccessCodeException) {
            }
        }

        $this->assertSame([12], $this->service->consume(4, $this->contact, $code));
    }

    public function test_code_is_bound_to_the_event_and_the_contact(): void
    {
        $code = $this->service->issue(4, $this->contact, [12]);
        $other = new AttendanceAccessContactDTO(AttendanceAccessChannel::EMAIL, 'other@example.com');

        try {
            $this->service->consume(5, $this->contact, $code);
            $this->fail('A code from another event must be rejected');
        } catch (InvalidAttendanceAccessCodeException) {
        }

        try {
            $this->service->consume(4, $other, $code);
            $this->fail('A code from another contact must be rejected');
        } catch (InvalidAttendanceAccessCodeException) {
        }

        $this->assertSame([12], $this->service->consume(4, $this->contact, $code));
    }

    public function test_code_is_stored_hashed_without_the_contact_in_clear(): void
    {
        $code = $this->service->issue(4, $this->contact, [12]);
        $key = 'attendance-access:code:'.$this->service->contactKey(4, $this->contact);
        $entry = $this->cache->get($key);

        $this->assertIsArray($entry);
        $this->assertStringNotContainsString($code, json_encode($entry));
        $this->assertStringNotContainsString('jo@example.com', $key.json_encode($entry));
    }

    public function test_new_request_replaces_the_previous_code(): void
    {
        $first = $this->service->issue(4, $this->contact, [12]);
        $second = $this->service->issue(4, $this->contact, [12]);

        if ($first !== $second) {
            try {
                $this->service->consume(4, $this->contact, $first);
                $this->fail('The previous code must no longer work');
            } catch (InvalidAttendanceAccessCodeException) {
            }
        }

        $this->assertSame([12], $this->service->consume(4, $this->contact, $second));
    }
}
