<?php

declare(strict_types=1);

namespace HiEvents\Repository\Interfaces;

use HiEvents\DomainObjects\OrganizerSmsSettingDomainObject;
use Illuminate\Support\Collection;

/**
 * @extends RepositoryInterface<OrganizerSmsSettingDomainObject>
 */
interface OrganizerSmsSettingRepositoryInterface extends RepositoryInterface
{
    /**
     * Settings of the organizers who can send SMS: their own line is connected (API key and number)
     * or they use a line shared by another organizer, or they left a shared line since the given date and may still
     * receive SMS on it. Secrets are not loaded.
     *
     * @return Collection<int, OrganizerSmsSettingDomainObject>
     */
    public function findWithSmsLine(int $limit, ?string $formerMembersDetachedSince = null): Collection;
}
