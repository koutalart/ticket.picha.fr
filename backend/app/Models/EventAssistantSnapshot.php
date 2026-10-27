<?php

declare(strict_types=1);

namespace HiEvents\Models;

class EventAssistantSnapshot extends BaseModel
{
    protected function getCastMap(): array
    {
        return ['snapshot' => 'array'];
    }
}
