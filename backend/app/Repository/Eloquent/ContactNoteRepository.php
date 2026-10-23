<?php

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\ContactNoteDomainObject;
use HiEvents\Models\ContactNote;
use HiEvents\Repository\Interfaces\ContactNoteRepositoryInterface;

class ContactNoteRepository extends BaseRepository implements ContactNoteRepositoryInterface
{
    protected function getModel(): string
    {
        return ContactNote::class;
    }

    public function getDomainObject(): string
    {
        return ContactNoteDomainObject::class;
    }
}
