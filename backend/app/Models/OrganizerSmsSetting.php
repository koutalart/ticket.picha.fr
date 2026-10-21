<?php

declare(strict_types=1);

namespace HiEvents\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizerSmsSetting extends BaseModel
{
    protected function getCastMap(): array
    {
        return [
            'api_key' => 'encrypted',
            'webhook_signing_key' => 'encrypted',
        ];
    }

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(Organizer::class);
    }
}
