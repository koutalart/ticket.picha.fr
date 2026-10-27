<?php

declare(strict_types=1);

namespace HiEvents\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailMessage extends BaseModel
{
    protected function getCastMap(): array
    {
        return [
            'attachments' => 'array',
        ];
    }

    public function email_thread(): BelongsTo
    {
        return $this->belongsTo(EmailThread::class);
    }
}
