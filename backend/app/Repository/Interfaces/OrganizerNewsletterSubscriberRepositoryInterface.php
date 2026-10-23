<?php

declare(strict_types=1);

namespace HiEvents\Repository\Interfaces;

use HiEvents\DomainObjects\OrganizerNewsletterSubscriberDomainObject;
use HiEvents\Services\Application\Handlers\Organizer\DTO\NewsletterSubscriberSearchDTO;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * @extends RepositoryInterface<OrganizerNewsletterSubscriberDomainObject>
 */
interface OrganizerNewsletterSubscriberRepositoryInterface extends RepositoryInterface
{
    /**
     * Inserts the subscriber, or re-activates an address unsubscribed before this new consent.
     *
     * @return bool false when the address is already an active subscriber (or the consent is older than its unsubscribe)
     */
    public function createIfNotSubscribed(array $attributes): bool;

    public function unsubscribe(int $organizerId, int $subscriberId): bool;

    /**
     * Adds the buyers who ticked the marketing opt-in at registration (orders.opted_into_marketing_at).
     *
     * @return int the subscribers added or re-activated, or with $dryRun the number that would be
     */
    public function subscribeConsentingBuyers(int $organizerId, string $consentText, ?int $orderId = null, bool $dryRun = false): int;

    public function searchForOrganizer(int $organizerId, NewsletterSubscriberSearchDTO $search): LengthAwarePaginator;
}
