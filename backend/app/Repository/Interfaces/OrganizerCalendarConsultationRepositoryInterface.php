<?php

declare(strict_types=1);

namespace HiEvents\Repository\Interfaces;

use HiEvents\DomainObjects\OrganizerCalendarConsultationDomainObject;
use HiEvents\Repository\DTO\OrganizerCalendarVisitorTotalDTO;
use Illuminate\Support\Collection;

/**
 * @extends RepositoryInterface<OrganizerCalendarConsultationDomainObject>
 */
interface OrganizerCalendarConsultationRepositoryInterface extends RepositoryInterface
{
    /**
     * @return Collection<int, OrganizerCalendarConsultationDomainObject>
     */
    public function findLatestForCalendar(int $calendarId, int $limit, ?string $since = null): Collection;

    /**
     * @return Collection<int, OrganizerCalendarVisitorTotalDTO>
     */
    public function totalsByVisitor(int $calendarId, ?string $since = null): Collection;
}
