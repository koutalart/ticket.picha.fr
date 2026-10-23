<?php

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\ContactTagDomainObject;
use HiEvents\Models\ContactTag;
use HiEvents\Repository\Interfaces\ContactTagRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ContactTagRepository extends BaseRepository implements ContactTagRepositoryInterface
{
    protected function getModel(): string
    {
        return ContactTag::class;
    }

    public function getDomainObject(): string
    {
        return ContactTagDomainObject::class;
    }

    public function getWithCountsForOrganizer(int $organizerId): Collection
    {
        return DB::table('contact_tags as ct')
            ->leftJoin('contact_tag_assignments as cta', 'cta.contact_tag_id', '=', 'ct.id')
            ->where('ct.organizer_id', $organizerId)
            ->groupBy('ct.id', 'ct.name')
            ->orderBy('ct.name')
            ->get(['ct.id', 'ct.name', DB::raw('count(cta.id)::int AS contacts_count')]);
    }

    public function findOrCreateByName(int $organizerId, string $name): ContactTagDomainObject
    {
        $name = trim($name);

        DB::statement(
            'INSERT INTO contact_tags (organizer_id, name, created_at, updated_at) VALUES (?, ?, now(), now()) ON CONFLICT (organizer_id, lower(name)) DO NOTHING',
            [$organizerId, $name],
        );

        $id = DB::table('contact_tags')
            ->where('organizer_id', $organizerId)
            ->whereRaw('lower(name) = lower(?)', [$name])
            ->value('id');

        return $this->findById($id);
    }
}
