<?php

declare(strict_types=1);

namespace HiEvents\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QrLinkVisit extends BaseModel
{
    protected function getTimestampsEnabled(): bool
    {
        return false;
    }

    protected function getCastMap(): array
    {
        return [
            'is_unique' => 'boolean',
        ];
    }

    public function qr_link(): BelongsTo
    {
        return $this->belongsTo(QrLink::class);
    }
}
