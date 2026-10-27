<?php

declare(strict_types=1);

namespace HiEvents\Repository\Interfaces;

use HiEvents\DomainObjects\OrganizerTeamNudgeDomainObject;

/**
 * @extends RepositoryInterface<OrganizerTeamNudgeDomainObject>
 */
interface OrganizerTeamNudgeRepositoryInterface extends RepositoryInterface
{
    /**
     * Records that a nudge for this awaited message is being sent. Null when another run already claimed it.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function claim(array $attributes): ?OrganizerTeamNudgeDomainObject;
}
