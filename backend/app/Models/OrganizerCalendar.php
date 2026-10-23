<?php

declare(strict_types=1);

namespace HiEvents\Models;

class OrganizerCalendar extends BaseModel
{
    protected function getCastMap(): array
    {
        return [
            'content' => 'array',
        ];
    }
}
