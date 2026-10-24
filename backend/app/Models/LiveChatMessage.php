<?php

declare(strict_types=1);

namespace HiEvents\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LiveChatMessage extends BaseModel
{
    public function live_chat_session(): BelongsTo
    {
        return $this->belongsTo(LiveChatSession::class);
    }
}
