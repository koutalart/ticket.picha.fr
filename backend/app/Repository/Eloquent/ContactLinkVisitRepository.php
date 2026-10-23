<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\ContactLinkVisitDomainObject;
use HiEvents\Models\ContactLinkVisit;
use HiEvents\Repository\Interfaces\ContactLinkVisitRepositoryInterface;

/**
 * @extends BaseRepository<ContactLinkVisitDomainObject>
 */
class ContactLinkVisitRepository extends BaseRepository implements ContactLinkVisitRepositoryInterface
{
    protected function getModel(): string
    {
        return ContactLinkVisit::class;
    }

    public function getDomainObject(): string
    {
        return ContactLinkVisitDomainObject::class;
    }

    public function hasVisitFromVisitor(int $contactId, string $visitorHash): bool
    {
        return ContactLinkVisit::query()
            ->where('contact_id', $contactId)
            ->where('visitor_hash', $visitorHash)
            ->exists();
    }
}
