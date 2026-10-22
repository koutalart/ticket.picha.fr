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
    public function createIfNotSubscribed(array $attributes): bool;

    public function searchForOrganizer(int $organizerId, NewsletterSubscriberSearchDTO $search): LengthAwarePaginator;
}
