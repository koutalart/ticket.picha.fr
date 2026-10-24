<?php

declare(strict_types=1);

namespace HiEvents\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialPost extends BaseModel
{
    protected function getCastMap(): array
    {
        return [
            'media' => 'array',
            'scheduled_at' => 'datetime',
            'published_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(Organizer::class);
    }
}
