<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\OrganizerCalendar;

use HiEvents\DomainObjects\Enums\OrganizerCalendarAccessMode;
use HiEvents\Exceptions\OrganizerCalendarConsultationUnavailableException;
use HiEvents\Exceptions\OrganizerCalendarNotFoundException;
use HiEvents\Exceptions\OrganizerCalendarRateLimitedException;
use HiEvents\Helper\CustomerLocale;
use HiEvents\Services\Application\Handlers\OrganizerCalendar\DTO\ConsultOrganizerCalendarDTO;
use HiEvents\Services\Domain\OrganizerCalendar\DTO\OrganizerCalendarAccessGrantDTO;
use HiEvents\Services\Domain\OrganizerCalendar\OrganizerCalendarAccessModeService;
use HiEvents\Services\Domain\OrganizerCalendar\OrganizerCalendarAccessService;
use HiEvents\Services\Domain\OrganizerCalendar\OrganizerCalendarConsultationService;
use HiEvents\Services\Domain\OrganizerCalendar\OrganizerCalendarIpRateLimiter;
use HiEvents\Services\Domain\OrganizerCalendar\OrganizerCalendarResolver;
use Psr\Log\LoggerInterface;

/**
 * Calendars in "name" mode: the visitor gives a first name, gets the same one year access key as after an SMS code,
 * and the opening is written to the consultation log. No SMS is ever sent.
 */
class ConsultOrganizerCalendarHandler
{
    public function __construct(
        private readonly OrganizerCalendarResolver $calendarResolver,
        private readonly OrganizerCalendarIpRateLimiter $ipRateLimiter,
        private readonly OrganizerCalendarAccessService $accessService,
        private readonly OrganizerCalendarConsultationService $consultationService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @throws OrganizerCalendarNotFoundException
     * @throws OrganizerCalendarConsultationUnavailableException
     * @throws OrganizerCalendarRateLimitedException
     */
    public function handle(ConsultOrganizerCalendarDTO $data): OrganizerCalendarAccessGrantDTO
    {
        $calendar = $this->calendarResolver->findBySlug($data->slug);

        if (OrganizerCalendarAccessModeService::modeOf($calendar) !== OrganizerCalendarAccessMode::NAME) {
            throw new OrganizerCalendarConsultationUnavailableException(
                __('This calendar is opened with a code.', [], CustomerLocale::get())
            );
        }

        $this->ipRateLimiter->hitConsultation($data->ipAddress);

        $grant = $this->accessService->grant($calendar->getId(), $data->visitorName, $data->deviceId);

        $this->consultationService->recordOpening(
            $calendar->getId(),
            $grant->accessId,
            $data->visitorName,
            $data->deviceId,
            $data->ipAddress,
            $data->userAgent,
        );

        $this->logger->info('Organizer calendar opened by first name', [
            'calendar_id' => $calendar->getId(),
            'access_id' => $grant->accessId,
            'visitor_name' => $data->visitorName,
            'device' => substr($data->deviceId, 0, 4),
            'ip' => $data->ipAddress,
        ]);

        return $grant;
    }
}
