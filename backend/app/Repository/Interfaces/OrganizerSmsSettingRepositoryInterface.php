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
     * or they use a line shared by another organizer. Secrets are not loaded.
     *
     * @return Collection<int, OrganizerSmsSettingDomainObject>
     */
    public function findWithSmsLine(int $limit): Collection;
}
