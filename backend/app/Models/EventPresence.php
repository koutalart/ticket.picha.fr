<?php

namespace HiEvents\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventPresence extends BaseModel
{
    protected $table = 'event_presences';

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
