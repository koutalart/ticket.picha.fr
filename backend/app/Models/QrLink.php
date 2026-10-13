<?php

declare(strict_types=1);

namespace HiEvents\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class QrLink extends BaseModel
{
    use SoftDeletes;

    protected function getCastMap(): array
    {
        return [
            'social_links' => 'array',
            'design' => 'array',
        ];
    }

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(Organizer::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function qr_link_visits(): HasMany
    {
        return $this->hasMany(QrLinkVisit::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
