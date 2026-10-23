<?php

declare(strict_types=1);

namespace HiEvents\Repository\Interfaces;

use HiEvents\DomainObjects\ContactLinkVisitDomainObject;

/**
 * @extends RepositoryInterface<ContactLinkVisitDomainObject>
 */
interface ContactLinkVisitRepositoryInterface extends RepositoryInterface
{
    public function hasVisitFromVisitor(int $contactId, string $visitorHash): bool;
}
