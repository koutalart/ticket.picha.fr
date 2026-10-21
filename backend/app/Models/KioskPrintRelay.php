<?php

namespace HiEvents\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KioskPrintRelay extends BaseModel
{
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
