<?php

namespace HiEvents\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrintJob extends BaseModel
{
    public function attendee(): BelongsTo
    {
        return $this->belongsTo(Attendee::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_user_id');
    }
}
