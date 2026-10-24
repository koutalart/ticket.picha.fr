<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\OrganizerCalendar;

use HiEvents\DomainObjects\Enums\OrganizerCalendarConsultationKind;
use HiEvents\DomainObjects\Generated\OrganizerCalendarConsultationDomainObjectAbstract as Consultation;
use HiEvents\DomainObjects\OrganizerCalendarAccessDomainObject;
use HiEvents\Repository\Interfaces\OrganizerCalendarConsultationRepositoryInterface;
use Illuminate\Support\Carbon;

/**
 * Who opened a private calendar and when: an "ouverture" on the Consult button,
 * then at most one "visite" per access every 10 minutes.
 */
class OrganizerCalendarConsultationService
{
    public const VISIT_INTERVAL_SECONDS = 600;

    public const RETENTION_DAYS = 180;

    public const USER_AGENT_MAX_LENGTH = 500;

    public function __construct(
        private readonly OrganizerCalendarConsultationRepositoryInterface $consultationRepository,
    ) {}

    public function recordOpening(
        int $calendarId,
        int $accessId,
        string $visitorName,
        string $deviceId,
        ?string $ipAddress,
        ?string $userAgent,
    ): void {
        $this->record($calendarId, $accessId, $visitorName, $deviceId, $ipAddress, $userAgent, OrganizerCalendarConsultationKind::OPENING);
    }

    public function recordVisit(OrganizerCalendarAccessDomainObject $access, ?string $ipAddress, ?string $userAgent): bool
    {
        $recent = $this->consultationRepository->countWhere([
            Consultation::ORGANIZER_CALENDAR_ACCESS_ID => $access->getId(),
            [Consultation::CREATED_AT, '>', Carbon::now()->subSeconds(self::VISIT_INTERVAL_SECONDS)->toDateTimeString()],
        ]);

        if ($recent > 0) {
            return false;
        }

        $this->record(
            $access->getOrganizerCalendarId(),
            $access->getId(),
            $access->getVisitorName(),
            $access->getDeviceId(),
            $ipAddress,
            $userAgent,
            OrganizerCalendarConsultationKind::VISIT,
        );

        return true;
    }

    public function purgeOlderThan(int $days = self::RETENTION_DAYS): int
    {
        return $this->consultationRepository->deleteWhere([
            [Consultation::CREATED_AT, '<', Carbon::now()->subDays($days)->toDateTimeString()],
        ]);
    }

    private function record(
        int $calendarId,
        int $accessId,
        ?string $visitorName,
        ?string $deviceId,
        ?string $ipAddress,
        ?string $userAgent,
        OrganizerCalendarConsultationKind $kind,
    ): void {
        $userAgent = $userAgent === null ? null : mb_substr(trim($userAgent), 0, self::USER_AGENT_MAX_LENGTH);

        $this->consultationRepository->create([
            Consultation::ORGANIZER_CALENDAR_ID => $calendarId,
            Consultation::ORGANIZER_CALENDAR_ACCESS_ID => $accessId,
            Consultation::VISITOR_NAME => $visitorName,
            Consultation::DEVICE_ID => $deviceId,
            Consultation::IP_ADDRESS => $ipAddress === null ? null : mb_substr($ipAddress, 0, 45),
            Consultation::USER_AGENT => $userAgent === '' ? null : $userAgent,
            Consultation::KIND => $kind->value,
            Consultation::CREATED_AT => Carbon::now()->toDateTimeString(),
        ]);
    }
}
