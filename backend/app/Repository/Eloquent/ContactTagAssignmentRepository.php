<?php

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\ContactTagAssignmentDomainObject;
use HiEvents\Models\ContactTagAssignment;
use HiEvents\Repository\Interfaces\ContactTagAssignmentRepositoryInterface;
use Illuminate\Support\Facades\DB;

class ContactTagAssignmentRepository extends BaseRepository implements ContactTagAssignmentRepositoryInterface
{
    protected function getModel(): string
    {
        return ContactTagAssignment::class;
    }

    public function getDomainObject(): string
    {
        return ContactTagAssignmentDomainObject::class;
    }

    public function assignToContacts(int $tagId, array $contactIds): int
    {
        $now = now();

        return DB::table('contact_tag_assignments')->insertOrIgnore(
            array_map(static fn(int $contactId) => [
                'contact_id' => $contactId,
                'contact_tag_id' => $tagId,
                'created_at' => $now,
                'updated_at' => $now,
            ], array_values(array_unique($contactIds)))
        );
    }
}
