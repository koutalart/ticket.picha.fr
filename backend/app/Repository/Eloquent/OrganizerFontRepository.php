<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\OrganizerFontDomainObject;
use HiEvents\Models\OrganizerFont;
use HiEvents\Repository\Interfaces\OrganizerFontRepositoryInterface;

/**
 * @extends BaseRepository<OrganizerFontDomainObject>
 */
class OrganizerFontRepository extends BaseRepository implements OrganizerFontRepositoryInterface
{
    protected function getModel(): string
    {
        return OrganizerFont::class;
    }

    public function getDomainObject(): string
    {
        return OrganizerFontDomainObject::class;
    }
}
