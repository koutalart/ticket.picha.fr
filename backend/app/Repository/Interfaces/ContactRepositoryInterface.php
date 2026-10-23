<?php

namespace HiEvents\Repository\Interfaces;

use HiEvents\DomainObjects\ContactDomainObject;
use HiEvents\Http\DTO\QueryParamsDTO;
use HiEvents\Services\Application\Handlers\Contact\DTO\ContactFilterDTO;
use HiEvents\Services\Application\Handlers\Contact\DTO\ContactSummaryDTO;
use HiEvents\Services\Application\Handlers\Contact\DTO\ContactTimelineItemDTO;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

/**
 * @extends RepositoryInterface<ContactDomainObject>
 */
interface ContactRepositoryInterface extends RepositoryInterface
{
    /**
     * Rebuilds contacts of an organizer from completed orders and their attendees.
     * When $emails is null, every buyer and attendee of the organizer is synced.
     *
     * @param  string[]|null  $emails  normalized (trimmed, lower-case) e-mail addresses
     */
    public function syncFromOrders(int $organizerId, ?array $emails = null): int;

    public function paginateForOrganizer(int $organizerId, ContactFilterDTO $filter, QueryParamsDTO $params): LengthAwarePaginator;

    public function getSummaryForOrganizer(int $organizerId, ContactFilterDTO $filter): ContactSummaryDTO;

    /**
     * @return LazyCollection<int, ContactDomainObject>
     */
    public function lazyForOrganizer(int $organizerId, ContactFilterDTO $filter): LazyCollection;

    /**
     * @return Collection<int, ContactTimelineItemDTO>
     */
    public function getTimeline(int $organizerId, string $email): Collection;

    /**
     * Existing contacts of an organizer keyed by e-mail and by phone, used to match imported rows.
     *
     * @return array{emails: array<string, object>, phones: array<string, object>}
     */
    public function getIdentityIndex(int $organizerId): array;

    public function deleteImportedContacts(int $contactImportId): int;
}
