<?php

namespace HiEvents\Services\Application\Handlers\CaseStudy;

use HiEvents\Repository\Interfaces\CaseStudyStatsRepositoryInterface;
use HiEvents\Services\Application\Handlers\CaseStudy\DTO\CaseStudyEventStatsDTO;

class GetCaseStudyStatsHandler
{
    public function __construct(
        private readonly CaseStudyStatsRepositoryInterface $repository,
    ) {}

    /**
     * @param  int[]  $eventIds
     * @return CaseStudyEventStatsDTO[]
     */
    public function handle(array $eventIds): array
    {
        return array_map(function (array $event): CaseStudyEventStatsDTO {
            $eventId = (int) $event['id'];
            $timezone = (string) $event['timezone'];
            $location = is_string($event['location_details'] ?? null)
                ? json_decode($event['location_details'], true)
                : ($event['location_details'] ?? null);

            return new CaseStudyEventStatsDTO(
                id: $eventId,
                title: (string) $event['title'],
                status: (string) $event['status'],
                category: $event['category'] ?? null,
                timezone: $timezone,
                start_local: $event['start_local'] ?? null,
                end_local: $event['end_local'] ?? null,
                organizer: (string) $event['organizer'],
                location: is_array($location) ? $location : null,
                first_sale_utc: $event['first_sale_utc'] ?? null,
                tickets_active: (int) $event['tickets_active'],
                orders_completed: (int) $event['orders_completed'],
                tickets_by_channel_product: $this->repository->getTicketsByChannelAndProduct($eventId),
                tickets_by_day: $this->repository->getTicketsByDay($eventId, $timezone),
                prices: $this->repository->getPrices($eventId),
                checked_in: $this->repository->countCheckedInAttendees($eventId),
                check_ins_by_hour: $this->repository->getCheckInsByHour($eventId, $timezone),
            );
        }, $this->repository->getEvents($eventIds));
    }
}
