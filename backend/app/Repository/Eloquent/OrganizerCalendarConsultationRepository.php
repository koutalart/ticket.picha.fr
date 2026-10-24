<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\Enums\OrganizerCalendarConsultationKind;
use HiEvents\DomainObjects\Generated\OrganizerCalendarConsultationDomainObjectAbstract as Consultation;
use HiEvents\DomainObjects\OrganizerCalendarConsultationDomainObject;
use HiEvents\Models\OrganizerCalendarConsultation;
use HiEvents\Repository\DTO\OrganizerCalendarVisitorTotalDTO;
use HiEvents\Repository\Interfaces\OrganizerCalendarConsultationRepositoryInterface;
use Illuminate\Support\Collection;

/**
 * @extends BaseRepository<OrganizerCalendarConsultationDomainObject>
 */
class OrganizerCalendarConsultationRepository extends BaseRepository implements OrganizerCalendarConsultationRepositoryInterface
{
    protected function getModel(): string
    {
        return OrganizerCalendarConsultation::class;
    }

    public function getDomainObject(): string
    {
        return OrganizerCalendarConsultationDomainObject::class;
    }

    public function findLatestForCalendar(int $calendarId, int $limit, ?string $since = null): Collection
    {
        $models = OrganizerCalendarConsultation::query()
            ->where(Consultation::ORGANIZER_CALENDAR_ID, $calendarId)
            ->when($since !== null, fn ($query) => $query->where(Consultation::CREATED_AT, '>=', $since))
            ->orderByDesc(Consultation::CREATED_AT)
            ->orderByDesc(Consultation::ID)
            ->limit($limit)
            ->get();

        return $this->handleResults($models);
    }

    public function totalsByVisitor(int $calendarId, ?string $since = null): Collection
    {
        return OrganizerCalendarConsultation::query()
            ->where(Consultation::ORGANIZER_CALENDAR_ID, $calendarId)
            ->when($since !== null, fn ($query) => $query->where(Consultation::CREATED_AT, '>=', $since))
            ->groupBy(Consultation::VISITOR_NAME)
            ->selectRaw('visitor_name, SUM(CASE WHEN kind = ? THEN 1 ELSE 0 END) AS openings, SUM(CASE WHEN kind = ? THEN 1 ELSE 0 END) AS visits, COUNT(DISTINCT device_id) AS devices, MAX(created_at) AS last_seen_at', [
                OrganizerCalendarConsultationKind::OPENING->value,
                OrganizerCalendarConsultationKind::VISIT->value,
            ])
            ->orderByDesc('last_seen_at')
            ->toBase()
            ->get()
            ->map(fn (object $row): OrganizerCalendarVisitorTotalDTO => new OrganizerCalendarVisitorTotalDTO(
                visitorName: $row->visitor_name,
                openings: (int) $row->openings,
                visits: (int) $row->visits,
                devices: (int) $row->devices,
                lastSeenAt: (string) $row->last_seen_at,
            ));
    }
}
