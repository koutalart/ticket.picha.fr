<?php

declare(strict_types=1);

namespace HiEvents\Models;

class SocialComment extends BaseModel
{
    protected function getCastMap(): array
    {
        return [
            'is_from_page' => 'boolean',
            'commented_at' => 'datetime',
        ];
    }
}
