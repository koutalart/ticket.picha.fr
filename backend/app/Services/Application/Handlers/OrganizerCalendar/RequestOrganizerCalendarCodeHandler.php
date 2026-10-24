<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\OrganizerCalendar;

use HiEvents\DomainObjects\Enums\OrganizerCalendarAccessMode;
use HiEvents\Exceptions\OrganizerCalendarCodeDisabledException;
use HiEvents\Exceptions\OrganizerCalendarCodeUnavailableException;
use HiEvents\Exceptions\OrganizerCalendarNotFoundException;
use HiEvents\Exceptions\OrganizerCalendarRateLimitedException;
use HiEvents\Helper\CustomerLocale;
use HiEvents\Helper\IpAddressMasker;
use HiEvents\Services\Application\Handlers\OrganizerCalendar\DTO\OrganizerCalendarCodeSentDTO;
use HiEvents\Services\Domain\OrganizerCalendar\OrganizerCalendarAccessModeService;
use HiEvents\Services\Domain\OrganizerCalendar\OrganizerCalendarCodeNotifier;
use HiEvents\Services\Domain\OrganizerCalendar\OrganizerCalendarCodeService;
use HiEvents\Services\Domain\OrganizerCalendar\OrganizerCalendarIpRateLimiter;
use HiEvents\Services\Domain\OrganizerCalendar\OrganizerCalendarResolver;
use Psr\Log\LoggerInterface;

class RequestOrganizerCalendarCodeHandler
{
    public function __construct(
        private readonly OrganizerCalendarResolver $calendarResolver,
        private readonly OrganizerCalendarIpRateLimiter $ipRateLimiter,
        private readonly OrganizerCalendarCodeService $codeService,
        private readonly OrganizerCalendarCodeNotifier $notifier,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @throws OrganizerCalendarNotFoundException
     * @throws OrganizerCalendarCodeDisabledException
     * @throws OrganizerCalendarRateLimitedException
     * @throws OrganizerCalendarCodeUnavailableException
     */
    public function handle(string $slug, string $ipAddress): OrganizerCalendarCodeSentDTO
    {
        $calendar = $this->calendarResolver->findBySlug($slug);
        $context = ['calendar_id' => $calendar->getId(), 'ip' => IpAddressMasker::mask($ipAddress)];

        if (OrganizerCalendarAccessModeService::modeOf($calendar) !== OrganizerCalendarAccessMode::CODE) {
            $this->logger->info('Organizer calendar code refused: calendar opened by first name', $context);

            throw new OrganizerCalendarCodeDisabledException(
                __('This calendar is not opened with an SMS code.', [], CustomerLocale::get())
            );
        }

        try {
            $this->ipRateLimiter->hitCodeRequest($ipAddress);
            $issued = $this->codeService->issue($calendar);
        } catch (OrganizerCalendarRateLimitedException $exception) {
            $this->logger->info('Organizer calendar code request rate limited', $context);

            throw $exception;
        }

        try {
            $this->notifier->send($calendar, $issued->code, $issued->validitySeconds);
        } catch (OrganizerCalendarCodeUnavailableException $exception) {
            $this->codeService->discard($issued->codeId);

            throw $exception;
        }

        $this->logger->info('Organizer calendar code sent', $context);

        $lastDigits = substr($calendar->getPhone(), -2);

        return new OrganizerCalendarCodeSentDTO(
            maskedPhone: '******'.$lastDigits,
            phoneLastDigits: $lastDigits,
            validitySeconds: $issued->validitySeconds,
        );
    }
}
