<?php

declare(strict_types=1);

namespace HiEvents\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class LiveChatSession extends BaseModel
{
    protected function getCastMap(): array
    {
        return [
            'needs_follow_up' => 'boolean',
        ];
    }

    public function live_chat_messages(): HasMany
    {
        return $this->hasMany(LiveChatMessage::class);
    }
}
