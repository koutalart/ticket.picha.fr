<?php

declare(strict_types=1);

namespace HiEvents\Models;

use HiEvents\Jobs\Chatwoot\MirrorUnattributedSmsToChatwootJob;

class SmsUnattributedMessage extends BaseModel
{
    protected static function booted(): void
    {
        static::created(static function (SmsUnattributedMessage $message): void {
            if (config('services.chatwoot.sms_inbox_id')) {
                MirrorUnattributedSmsToChatwootJob::dispatch($message->id);
            }
        });
    }

    protected function getCastMap(): array
    {
        return [
            'opt_out_applied' => 'boolean',
        ];
    }
}
