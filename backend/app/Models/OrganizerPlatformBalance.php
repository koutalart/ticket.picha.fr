<?php

namespace HiEvents\Models;

class OrganizerPlatformBalance extends BaseModel
{
    protected function getCastMap(): array
    {
        return [
            'initial_amount' => 'float',
            'remaining_amount' => 'float',
        ];
    }
}
