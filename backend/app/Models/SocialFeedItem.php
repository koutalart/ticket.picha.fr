<?php

declare(strict_types=1);

namespace HiEvents\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class SocialFeedItem extends BaseModel
{
    protected function getCastMap(): array
    {
        return [
            'children' => 'array',
            'published_at' => 'datetime',
            'comments_synced_at' => 'datetime',
        ];
    }

    public function comments(): HasMany
    {
        return $this->hasMany(SocialComment::class);
    }
}
