<?php

declare(strict_types=1);

namespace HiEvents\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizerSocialConnection extends BaseModel
{
    protected function getCastMap(): array
    {
        return [
            'user_access_token' => 'encrypted',
            'page_access_token' => 'encrypted',
            'granted_scopes' => 'array',
            'user_access_token_expires_at' => 'datetime',
            'last_error_at' => 'datetime',
        ];
    }

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(Organizer::class);
    }
}
