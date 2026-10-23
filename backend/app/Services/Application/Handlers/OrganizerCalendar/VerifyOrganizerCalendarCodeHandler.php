<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\OrganizerCalendar;

use HiEvents\Exceptions\InvalidOrganizerCalendarCodeException;
use HiEvents\Exceptions\OrganizerCalendarNotFoundException;
use HiEvents\Exceptions\OrganizerCalendarRateLimitedException;
use HiEvents\Helper\IpAddressMasker;
use HiEvents\Services\Domain\OrganizerCalendar\DTO\OrganizerCalendarAccessGrantDTO;
use HiEvents\Services\Domain\OrganizerCalendar\OrganizerCalendarAccessService;
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
        private readonly OrganizerCalendarAccessService $accessService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @throws OrganizerCalendarNotFoundException
     * @throws OrganizerCalendarRateLimitedException
     * @throws InvalidOrganizerCalendarCodeException
     */
    public function handle(string $slug, string $code, string $ipAddress): OrganizerCalendarAccessGrantDTO
    {
        $calendar = $this->calendarResolver->findBySlug($slug);
        $context = ['calendar_id' => $calendar->getId(), 'ip' => IpAddressMasker::mask($ipAddress)];

        $this->ipRateLimiter->hitVerification($ipAddress);

        try {
            $this->codeService->verify($calendar, $code);
        } catch (InvalidOrganizerCalendarCodeException $exception) {
            $this->logger->info('Organizer calendar code rejected', $context);

            throw $exception;
        }

        $grant = $this->accessService->grant($calendar->getId());
        $this->logger->info('Organizer calendar access granted', $context + ['access_id' => $grant->accessId]);

        return $grant;
    }
}
