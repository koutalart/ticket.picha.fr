<?php

declare(strict_types=1);

namespace HiEvents\Models;

class SmsLineKeyword extends BaseModel
{
    protected function getCastMap(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
