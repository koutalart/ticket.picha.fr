<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\OrganizerCalendar;

use HiEvents\DomainObjects\OrganizerCalendarDomainObject;
use HiEvents\Exceptions\InvalidOrganizerCalendarCodeException;
use HiEvents\Exceptions\OrganizerCalendarNotFoundException;
use HiEvents\Exceptions\OrganizerCalendarRateLimitedException;
use HiEvents\Helper\IpAddressMasker;
use HiEvents\Services\Application\Handlers\OrganizerCalendar\DTO\VerifiedOrganizerCalendarAccessDTO;
use HiEvents\Services\Domain\OrganizerCalendar\OrganizerCalendarAccessService;
use HiEvents\Services\Domain\OrganizerCalendar\OrganizerCalendarAdminCodeService;
use HiEvents\Services\Domain\OrganizerCalendar\OrganizerCalendarCodeService;
use HiEvents\Services\Domain\OrganizerCalendar\OrganizerCalendarIpRateLimiter;
use HiEvents\Services\Domain\OrganizerCalendar\OrganizerCalendarResolver;
use Psr\Log\LoggerInterface;

class VerifyOrganizerCalendarCodeHandler
{
    public function __construct(
        private readonly OrganizerCalendarResolver $calendarResolver,
        private readonly OrganizerCalendarIpRateLimiter $ipRateLimiter,
        private readonly OrganizerCalendarCodeService $codeService,
        private readonly OrganizerCalendarAdminCodeService $adminCodeService,
        private readonly OrganizerCalendarAccessService $accessService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @throws OrganizerCalendarNotFoundException
     * @throws OrganizerCalendarRateLimitedException
     * @throws InvalidOrganizerCalendarCodeException
     */
    public function handle(string $slug, string $code, string $ipAddress): VerifiedOrganizerCalendarAccessDTO
    {
        $calendar = $this->calendarResolver->findBySlug($slug);
        $admin = OrganizerCalendarAdminCodeService::looksLikeAdminCode($code);
        $context = ['calendar_id' => $calendar->getId(), 'ip' => IpAddressMasker::mask($ipAddress), 'admin' => $admin];

        $this->ipRateLimiter->hitVerification($ipAddress);

        try {
            if ($admin) {
                $this->ipRateLimiter->assertAdminAttemptAllowed($ipAddress);
                $this->verifyAdminCode($calendar, $code, $ipAddress);
            } else {
                $this->codeService->verify($calendar, $code);
            }
        } catch (InvalidOrganizerCalendarCodeException $exception) {
            $this->logger->info('Organizer calendar code rejected', $context);

            throw $exception;
        }

        $grant = $this->accessService->grant($calendar->getId());
        $this->logger->info('Organizer calendar access granted', $context + ['access_id' => $grant->accessId]);

        return new VerifiedOrganizerCalendarAccessDTO(grant: $grant, admin: $admin);
    }

    /**
     * @throws InvalidOrganizerCalendarCodeException
     */
    private function verifyAdminCode(OrganizerCalendarDomainObject $calendar, string $code, string $ipAddress): void
    {
        try {
            $this->adminCodeService->verify($calendar, $code);
        } catch (InvalidOrganizerCalendarCodeException $exception) {
            $this->ipRateLimiter->hitAdminFailure($ipAddress);

            throw $exception;
        }
    }
}
