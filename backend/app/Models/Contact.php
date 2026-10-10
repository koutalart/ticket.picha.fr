<?php

namespace HiEvents\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contact extends BaseModel
{
    protected $table = 'contacts';

    protected function getCastMap(): array
    {
        return [
            'total_spent' => 'float',
        ];
    }

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(Organizer::class);
    }

    public function contact_notes(): HasMany
    {
        return $this->hasMany(ContactNote::class);
    }

    public function contact_tags(): BelongsToMany
    {
        return $this->belongsToMany(ContactTag::class, 'contact_tag_assignments');
    }
}
