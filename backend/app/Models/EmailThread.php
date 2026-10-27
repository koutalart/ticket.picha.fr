<?php

declare(strict_types=1);

namespace HiEvents\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailThread extends BaseModel
{
    public function organizer(): BelongsTo
    {
        return $this->belongsTo(Organizer::class);
    }

    public function email_messages(): HasMany
    {
        return $this->hasMany(EmailMessage::class);
    }
}
