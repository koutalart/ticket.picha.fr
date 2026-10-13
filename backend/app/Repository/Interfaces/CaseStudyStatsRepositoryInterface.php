<?php

namespace HiEvents\Repository\Interfaces;

interface CaseStudyStatsRepositoryInterface
{
    /**
     * @param  int[]  $eventIds
     * @return array<int, array<string, mixed>>
     */
    public function getEvents(array $eventIds): array;

    /**
     * @return array<int, array{channel: string, product: string, tickets: int}>
     */
    public function getTicketsByChannelAndProduct(int $eventId): array;

    /**
     * @return array<int, array{day: string, channel: string, tickets: int}>
     */
    public function getTicketsByDay(int $eventId, string $timezone): array;

    /**
     * @return array<int, array{product: string, label: ?string, price: float, capacity: ?int, quantity_sold: int}>
     */
    public function getPrices(int $eventId): array;

    public function countCheckedInAttendees(int $eventId): int;

    /**
     * @return array<int, array{hour: string, scans: int}>
     */
    public function getCheckInsByHour(int $eventId, string $timezone): array;
}
