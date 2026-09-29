<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\BoxOffice;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\Generated\AttendeeCheckInDomainObjectAbstract;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\Helper\KioskSentinelEmail;
use HiEvents\Http\DTO\QueryParamsDTO;
use HiEvents\Repository\Interfaces\AttendeeCheckInRepositoryInterface;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Services\Application\Handlers\BoxOffice\DTO\BoxOfficeAttendeeSearchResultDTO;
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
        private readonly ProductRepositoryInterface $productRepository,
        private readonly AttendeeCheckInRepositoryInterface $attendeeCheckInRepository,
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

        if ($attendees->isEmpty()) {
            return collect();
        }

        $productTitles = $this->productRepository
            ->findWhereIn('id', $attendees->map(fn (AttendeeDomainObject $attendee) => $attendee->getProductId())->unique()->all())
            ->mapWithKeys(fn (ProductDomainObject $product) => [$product->getId() => $product->getTitle()]);

        $checkedInAt = $this->attendeeCheckInRepository
            ->findWhereIn(
                AttendeeCheckInDomainObjectAbstract::ATTENDEE_ID,
                $attendees->map(fn (AttendeeDomainObject $attendee) => $attendee->getId())->all(),
                [AttendeeCheckInDomainObjectAbstract::EVENT_ID => $eventId],
            )
            ->groupBy(fn ($checkIn) => $checkIn->getAttendeeId())
            ->map(fn (Collection $checkIns) => $checkIns->min(fn ($checkIn) => $checkIn->getCreatedAt()));

        return $attendees->map(fn (AttendeeDomainObject $attendee) => new BoxOfficeAttendeeSearchResultDTO(
            public_id: $attendee->getPublicId(),
            first_name: $attendee->getFirstName(),
            last_name: $attendee->getLastName(),
            email: KioskSentinelEmail::isKioskSentinelEmail((string) $attendee->getEmail()) ? null : $attendee->getEmail(),
            product_title: $productTitles->get($attendee->getProductId()),
            status: $attendee->getStatus(),
            checked_in_at: $checkedInAt->get($attendee->getId()),
        ))->values();
    }
}
