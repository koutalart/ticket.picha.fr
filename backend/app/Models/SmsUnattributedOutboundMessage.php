<?php

declare(strict_types=1);

namespace HiEvents\Models;

use HiEvents\DomainObjects\Status\SmsMessageStatus;
use HiEvents\Jobs\Chatwoot\MirrorUnattributedSmsToChatwootJob;

class SmsUnattributedOutboundMessage extends BaseModel
{
    protected static function booted(): void
    {
        static::created(static function (SmsUnattributedOutboundMessage $message): void {
            if (config('services.chatwoot.sms_inbox_id')) {
                MirrorUnattributedSmsToChatwootJob::dispatch($message->id, outbound: true);
            }
        });

        static::updated(static function (SmsUnattributedOutboundMessage $message): void {
            if (config('services.chatwoot.sms_inbox_id')
                && $message->wasChanged('status')
                && in_array($message->status, [SmsMessageStatus::FAILED->name, SmsMessageStatus::EXPIRED->name], true)) {
                MirrorUnattributedSmsToChatwootJob::dispatch($message->id, outbound: true, failure: true);
            }
        });
    }
}
