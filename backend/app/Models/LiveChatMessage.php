<?php

declare(strict_types=1);

namespace HiEvents\Models;

use HiEvents\Jobs\Chatwoot\MirrorLiveChatMessageToChatwootJob;
use HiEvents\Services\Domain\Chatwoot\ChatwootLiveChatMirrorService;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LiveChatMessage extends BaseModel
{
    protected static function booted(): void
    {
        static::created(static function (LiveChatMessage $message): void {
            if (config('services.chatwoot.live_chat_inbox_id') && ! ChatwootLiveChatMirrorService::isSuppressed()) {
                MirrorLiveChatMessageToChatwootJob::dispatch($message->id);
            }
        });
    }

    public function live_chat_session(): BelongsTo
    {
        return $this->belongsTo(LiveChatSession::class);
    }
}
