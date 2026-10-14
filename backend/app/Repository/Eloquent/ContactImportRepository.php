<?php

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\ContactImportDomainObject;
use HiEvents\Models\ContactImport;
use HiEvents\Repository\Interfaces\ContactImportRepositoryInterface;

class ContactImportRepository extends BaseRepository implements ContactImportRepositoryInterface
{
    protected function getModel(): string
    {
        return ContactImport::class;
    }

    public function getDomainObject(): string
    {
        return ContactImportDomainObject::class;
    }
}
