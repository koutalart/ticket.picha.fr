<?php

namespace HiEvents\Models;

class OrganizerPlatformBalanceDeduction extends BaseModel
{
    protected function getCastMap(): array
    {
        return [
            'amount' => 'float',
        ];
    }
}
