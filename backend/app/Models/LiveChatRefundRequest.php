<?php

declare(strict_types=1);

namespace HiEvents\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LiveChatRefundRequest extends BaseModel
{
    protected function getCastMap(): array
    {
        return [
            'attendee_ids' => 'array',
            'amount' => 'float',
        ];
    }

    public function live_chat_session(): BelongsTo
    {
        return $this->belongsTo(LiveChatSession::class);
    }
}
