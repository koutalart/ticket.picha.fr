<?php

namespace HiEvents\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventFeedback extends BaseModel
{
    protected $table = 'event_feedback';

    protected function getCastMap(): array
    {
        return [
            'publish_consent' => 'boolean',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
