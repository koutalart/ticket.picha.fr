<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\OrganizerNewsletterSubscriberDomainObject;
use HiEvents\Models\OrganizerNewsletterSubscriber;
use HiEvents\Repository\Interfaces\OrganizerNewsletterSubscriberRepositoryInterface;

/**
 * @extends BaseRepository<OrganizerNewsletterSubscriberDomainObject>
 */
class OrganizerNewsletterSubscriberRepository extends BaseRepository implements OrganizerNewsletterSubscriberRepositoryInterface
{
    protected function getModel(): string
    {
        return OrganizerNewsletterSubscriber::class;
    }

    public function getDomainObject(): string
    {
        return OrganizerNewsletterSubscriberDomainObject::class;
    }

    public function createIfNotSubscribed(array $attributes): bool
    {
        $now = now();

        return OrganizerNewsletterSubscriber::query()->insertOrIgnore([
            ...$attributes,
            'created_at' => $now,
            'updated_at' => $now,
        ]) > 0;
    }
}
