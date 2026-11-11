<?php

declare(strict_types=1);

namespace HiEvents\Models;

use HiEvents\DomainObjects\Status\SmsMessageStatus;
use HiEvents\Jobs\Chatwoot\MirrorSmsMessageToChatwootJob;
use HiEvents\Services\Domain\Chatwoot\ChatwootSmsMirrorService;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmsMessage extends BaseModel
{
    protected static function booted(): void
    {
        static::created(static function (SmsMessage $message): void {
            if (config('services.chatwoot.sms_inbox_id') && ! ChatwootSmsMirrorService::isSuppressed()) {
                MirrorSmsMessageToChatwootJob::dispatch($message->id);
            }
        });

        static::updated(static function (SmsMessage $message): void {
            if (config('services.chatwoot.sms_inbox_id')
                && $message->wasChanged('status')
                && in_array($message->status, [SmsMessageStatus::FAILED->name, SmsMessageStatus::EXPIRED->name], true)) {
                MirrorSmsMessageToChatwootJob::dispatch($message->id, failure: true);
            }
        });
    }

    public function sms_conversation(): BelongsTo
    {
        return $this->belongsTo(SmsConversation::class);
    }
}
