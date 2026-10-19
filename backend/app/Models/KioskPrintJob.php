<?php

namespace HiEvents\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KioskPrintJob extends BaseModel
{
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
