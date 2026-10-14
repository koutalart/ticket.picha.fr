<?php

declare(strict_types=1);

namespace HiEvents\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class QrCampaign extends BaseModel
{
    use SoftDeletes;

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(Organizer::class);
    }

    public function qr_links(): HasMany
    {
        return $this->hasMany(QrLink::class);
    }
}
