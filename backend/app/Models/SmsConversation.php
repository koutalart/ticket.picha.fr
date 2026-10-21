<?php

declare(strict_types=1);

namespace HiEvents\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SmsConversation extends BaseModel
{
    public function organizer(): BelongsTo
    {
        return $this->belongsTo(Organizer::class);
    }

    public function sms_messages(): HasMany
    {
        return $this->hasMany(SmsMessage::class);
    }
}
