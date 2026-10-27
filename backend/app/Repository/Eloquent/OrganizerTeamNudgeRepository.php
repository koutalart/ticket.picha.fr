<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\OrganizerTeamNudgeDomainObject;
use HiEvents\Models\OrganizerTeamNudge;
use HiEvents\Repository\Interfaces\OrganizerTeamNudgeRepositoryInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * @extends BaseRepository<OrganizerTeamNudgeDomainObject>
 */
class OrganizerTeamNudgeRepository extends BaseRepository implements OrganizerTeamNudgeRepositoryInterface
{
    protected function getModel(): string
    {
        return OrganizerTeamNudge::class;
    }

    public function getDomainObject(): string
    {
        return OrganizerTeamNudgeDomainObject::class;
    }

    public function claim(array $attributes): ?OrganizerTeamNudgeDomainObject
    {
        try {
            return DB::transaction(fn () => $this->create($attributes));
        } catch (UniqueConstraintViolationException) {
            $this->resetModel();

            return null;
        }
    }
}
