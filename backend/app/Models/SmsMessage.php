<?php

declare(strict_types=1);

namespace HiEvents\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmsMessage extends BaseModel
{
    public function sms_conversation(): BelongsTo
    {
        return $this->belongsTo(SmsConversation::class);
    }
}
