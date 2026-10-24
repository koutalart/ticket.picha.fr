<?php

declare(strict_types=1);

namespace HiEvents\Models;

class SocialMediaFile extends BaseModel
{
    protected function getCastMap(): array
    {
        return [
            'purge_after' => 'datetime',
        ];
    }
}
