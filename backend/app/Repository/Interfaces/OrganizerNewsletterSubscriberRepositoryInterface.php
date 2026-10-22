<?php

declare(strict_types=1);

namespace HiEvents\Repository\Interfaces;

use HiEvents\DomainObjects\OrganizerNewsletterSubscriberDomainObject;

/**
 * @extends RepositoryInterface<OrganizerNewsletterSubscriberDomainObject>
 */
interface OrganizerNewsletterSubscriberRepositoryInterface extends RepositoryInterface
{
    public function createIfNotSubscribed(array $attributes): bool;
}
