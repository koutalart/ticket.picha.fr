<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\BoxOffice;

use HiEvents\Http\DTO\QueryParamsDTO;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Services\Application\Handlers\BoxOffice\DTO\BoxOfficeAttendeeSearchResultDTO;
use HiEvents\Services\Domain\BoxOffice\BoxOfficeAttendeeSummaryService;
use Illuminate\Support\Collection;

/**
 * Welcome desk lookup: find a registered attendee of one event by name,
 * e-mail or ticket number (a QR scanner types the public id).
 */
class SearchBoxOfficeAttendeesHandler
{
    private const LIMIT = 20;

    public function __construct(
        private readonly AttendeeRepositoryInterface $attendeeRepository,
        private readonly BoxOfficeAttendeeSummaryService $attendeeSummaryService,
    ) {}

    /**
     * @return Collection<int, BoxOfficeAttendeeSearchResultDTO>
     */
    public function handle(int $eventId, string $query): Collection
    {
        $query = trim($query);
        if ($query === '') {
            return collect();
        }

        $attendees = collect($this->attendeeRepository->findByEventId($eventId, new QueryParamsDTO(
            per_page: self::LIMIT,
            query: $query,
        ))->items());

        return $this->attendeeSummaryService->summarize($eventId, $attendees);
    }
}
