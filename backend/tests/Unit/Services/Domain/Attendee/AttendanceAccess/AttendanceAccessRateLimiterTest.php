<?php

namespace Tests\Unit\Services\Domain\Attendee\AttendanceAccess;

use HiEvents\Exceptions\AttendanceAccessRateLimitedException;
use HiEvents\Services\Domain\Attendee\AttendanceAccess\AttendanceAccessRateLimiter;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository;
use Tests\TestCase;

class AttendanceAccessRateLimiterTest extends TestCase
{
    private AttendanceAccessRateLimiter $limiter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->limiter = new AttendanceAccessRateLimiter(new RateLimiter(new Repository(new ArrayStore)));
    }

    public function test_three_requests_per_contact_per_hour(): void
    {
        for ($request = 0; $request < AttendanceAccessRateLimiter::MAX_REQUESTS_PER_CONTACT; $request++) {
            $this->limiter->hitCodeRequest('contact-a', '10.0.0.'.$request);
        }

        $this->expectException(AttendanceAccessRateLimitedException::class);
        $this->limiter->hitCodeRequest('contact-a', '10.0.0.99');
    }

    public function test_ten_requests_per_ip_per_hour_across_contacts(): void
    {
        for ($request = 0; $request < AttendanceAccessRateLimiter::MAX_REQUESTS_PER_IP; $request++) {
            $this->limiter->hitCodeRequest('contact-'.$request, '10.0.0.1');
        }

        $this->expectException(AttendanceAccessRateLimitedException::class);
        $this->limiter->hitCodeRequest('contact-new', '10.0.0.1');
    }

    public function test_other_contacts_and_ips_are_not_affected(): void
    {
        for ($request = 0; $request < AttendanceAccessRateLimiter::MAX_REQUESTS_PER_CONTACT; $request++) {
            $this->limiter->hitCodeRequest('contact-a', '10.0.0.1');
        }

        $this->limiter->hitCodeRequest('contact-b', '10.0.0.2');
        $this->addToAssertionCount(1);
    }

    public function test_verifications_are_limited_per_ip(): void
    {
        for ($request = 0; $request < AttendanceAccessRateLimiter::MAX_VERIFICATIONS_PER_IP; $request++) {
            $this->limiter->hitVerification('10.0.0.1');
        }

        $this->expectException(AttendanceAccessRateLimitedException::class);
        $this->limiter->hitVerification('10.0.0.1');
    }
}
