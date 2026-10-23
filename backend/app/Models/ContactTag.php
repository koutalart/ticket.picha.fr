<?php

namespace HiEvents\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ContactTag extends BaseModel
{
    protected $table = 'contact_tags';

    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class, 'contact_tag_assignments');
    }
}
